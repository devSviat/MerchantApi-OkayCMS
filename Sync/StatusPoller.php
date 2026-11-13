<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiIssuesEntity;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiRunsEntity;
use Okay\Modules\Sviat\MerchantApi\Google\ApiError;
use Okay\Modules\Sviat\MerchantApi\Google\AuthFailure;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClientFactory;
use Okay\Modules\Sviat\MerchantApi\Services\CredentialStore;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;

/**
 * Раз на добу: статуси й проблеми всіх товарів акаунта (products.list, по 1000 на сторінку) і список джерел.
 * Зберігаються лише товари з проблемами — щоб адмінка показувала, що виправити, а не весь каталог.
 */
class StatusPoller
{
    // Сторінок за запуск: 1000 товарів на сторінку, з запасом на кілька мов і ріст каталогу.
    private const int MAX_PAGES = 100;
    // Журнал запусків: ~200 рядків на добу на дві мови; місяця досить, щоб розібратись із будь-яким збоєм.
    private const string KEEP_RUNS = 'P30D';

    public function __construct(
        private ModuleSettings $settings,
        private CredentialStore $credentials,
        private MerchantClientFactory $clients,
        private EntityFactory $entityFactory,
        private Clock $clock,
        private ModuleLock $lock,
    ) {
    }

    /**
     * @param bool $manual кнопка в адмінці: лише читання, тож пауза її не зупиняє
     * @return bool false — опитування вже йде в іншому процесі
     */
    public function poll(bool $manual = false): bool
    {
        return $this->lock->run(ModuleLock::STATUS_POLL, fn() => $this->pollLocked($manual));
    }

    private function pollLocked(bool $manual): void
    {
        $this->pruneRuns();

        $key = $this->credentials->load();
        $accountId = $this->settings->accountId();
        if (!self::shouldPoll($this->settings->isPaused(), $manual, $key !== null && $accountId !== null)) {
            return;
        }

        $client = $this->clients->create($key, $accountId);
        $rows = [];
        $summary = ['products' => 0, 'disapproved' => 0, 'demoted' => 0, 'by_source' => []];
        $pageToken = null;
        try {
            // Список джерел — заразом: назви й кількості в адмінці мають збігатися з Merchant Center після однієї кнопки.
            $this->settings->setSourcesCache($client->listDataSources(), $this->clock->now());
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $result = $client->listProducts($pageToken);
                foreach ($result['products'] as $product) {
                    $summary['products']++;
                    // Скільки товарів у кожному джерелі — за цим видно перехід із файлу: insert переносить товар.
                    $source = self::sourceOf($product);
                    $summary['by_source'][$source] = ($summary['by_source'][$source] ?? 0) + 1;
                    $row = self::issueRow($product);
                    if ($row !== null) {
                        $summary[$row['severity'] === 'DISAPPROVED' ? 'disapproved' : 'demoted']++;
                        $rows[] = $row;
                    }
                }
                $pageToken = $result['nextPageToken'];
                if ($pageToken === null) {
                    break;
                }
            }
            if ($pageToken !== null) {
                // Неповні дані не замінюють повний набір проблем: зникли б товари, яких просто не дочитали.
                $this->settings->setStatusSummary(['error' => 'too_many_pages', 'polled_at' => $this->clock->now()->format(DATE_ATOM)]);
                return;
            }
        } catch (ApiError $e) {
            $this->settings->setStatusSummary(['error' => $e->describe(), 'polled_at' => $this->clock->now()->format(DATE_ATOM)]);
            return;
        } catch (AuthFailure $e) {
            $this->settings->setStatusSummary(['error' => 'auth: ' . $e->getReason(), 'polled_at' => $this->clock->now()->format(DATE_ATOM)]);
            return;
        }

        $this->replace($rows);
        $this->settings->setStatusSummary($summary + ['polled_at' => $this->clock->now()->format(DATE_ATOM)]);
    }

    /**
     * @param array<string, mixed> $product ресурс Product (Merchant API v1)
     * @return array{content_language: string, feed_label: string, offer_id: string, severity: string, issues: string}|null
     */
    public static function issueRow(array $product): ?array
    {
        $issues = [];
        $severity = null;
        foreach ((array) ($product['productStatus']['itemLevelIssues'] ?? []) as $issue) {
            if (!is_array($issue) || !in_array($issue['severity'] ?? null, ['DISAPPROVED', 'DEMOTED'], true)) {
                continue;
            }
            $severity = $severity === 'DISAPPROVED' || $issue['severity'] === 'DISAPPROVED' ? 'DISAPPROVED' : 'DEMOTED';
            $issues[] = [
                'severity' => $issue['severity'],
                'code' => self::text($issue['code'] ?? '', 100),
                'attribute' => self::text($issue['attribute'] ?? '', 100),
                'context' => self::text($issue['reportingContext'] ?? '', 50),
                'description' => self::text($issue['description'] ?? '', 300),
                'detail' => self::text($issue['detail'] ?? '', 500),
            ];
        }
        if ($severity === null) {
            return null;
        }

        return [
            'content_language' => self::text($product['contentLanguage'] ?? '', 8),
            'feed_label' => self::text($product['feedLabel'] ?? '', 20),
            'offer_id' => self::text($product['offerId'] ?? '', 64),
            'severity' => $severity,
            'issues' => (string) json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** Пауза зупиняє щоденне опитування, але не кнопку: під час підготовки переходу кількість потрібна саме тоді. */
    public static function shouldPoll(bool $paused, bool $manual, bool $connected): bool
    {
        return $connected && (!$paused || $manual);
    }

    /**
     * @param array<string, mixed> $product ресурс Product (Merchant API v1)
     * @return string ім'я джерела або '' для чужого значення
     */
    public static function sourceOf(array $product): string
    {
        $source = $product['dataSource'] ?? null;

        return is_string($source) && preg_match('~\Aaccounts/\d+/dataSources/\d+\z~', $source) ? $source : '';
    }

    private function pruneRuns(): void
    {
        $entity = $this->entityFactory->get(MerchantApiRunsEntity::class);
        $before = $this->clock->now()->sub(new \DateInterval(self::KEEP_RUNS))->format('Y-m-d H:i:s');
        $ids = $entity->cols(['id'])->noLimit()->find(['started_before' => $before]);
        if ($ids !== []) {
            $entity->delete(array_map(intval(...), $ids));
        }
    }

    /** @param list<array<string, string>> $rows */
    private function replace(array $rows): void
    {
        $entity = $this->entityFactory->get(MerchantApiIssuesEntity::class);
        $ids = $entity->cols(['id'])->noLimit()->find();
        if ($ids !== []) {
            $entity->delete(array_map(intval(...), $ids));
        }
        $polledAt = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $entity->add($row + ['polled_at' => $polledAt]);
        }
    }

    private static function text(mixed $value, int $max): string
    {
        return is_scalar($value) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? ''), 0, $max) : '';
    }
}
