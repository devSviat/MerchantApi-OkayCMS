<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use DateTimeImmutable;
use Okay\Modules\Sviat\MerchantApi\Google\ApiError;
use Okay\Modules\Sviat\MerchantApi\Google\AuthFailure;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClient;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClientFactory;
use Okay\Modules\Sviat\MerchantApi\Mapping\ItemMapper;
use Okay\Modules\Sviat\MerchantApi\Mapping\MappedItem;
use Okay\Modules\Sviat\MerchantApi\Services\CredentialStore;
use Okay\Modules\Sviat\MerchantApi\Services\LanguageTarget;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Services\Requirements;
use Throwable;

/**
 * Один запуск синхронізації однієї мови. Викликається задачею планувальника — по задачі на мову,
 * тобто кожна мова в окремому процесі (див. FeedItemBuilder::claimLanguage).
 */
class SyncRunner
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_DRY = 'dry';
    public const string STATUS_PARTIAL = 'partial';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_RUNNING = 'running';

    // Скільки прикладів помилок і невалідних товарів класти в журнал запуску.
    private const int SAMPLE = 20;
    // Стільки поспіль недоступностей Google — і решта чекає наступного запуску.
    private const int TRANSIENT_STOP = 3;
    // Стільки поспіль однакових відмов — це вже не товари, а налаштування (джерело, мітка, права).
    private const int REPEATED_STOP = 5;

    private ?int $runId = null;

    public function __construct(
        private ModuleSettings $settings,
        private CredentialStore $credentials,
        private Requirements $requirements,
        private ItemSource $items,
        private StateStore $states,
        private RunLog $log,
        private MerchantClientFactory $clients,
        private Clock $clock,
    ) {
    }

    public function runLanguage(int $langId): void
    {
        $target = $this->settings->languages()[$langId] ?? null;
        if ($target === null || $this->settings->isPaused()) {
            return;
        }

        $startedAt = $this->clock->now();
        $run = ['lang_id' => $langId, 'started_at' => $startedAt->format('Y-m-d H:i:s'), 'dry_run' => (int) $this->settings->isDryRun()];
        $this->runId = $this->log->start($run + ['status' => self::STATUS_RUNNING]);
        try {
            $this->run($target, $startedAt, $run);
        } catch (Throwable $e) {
            // Лише клас і очищений текст: сам виняток (з аргументами в трасі) у журнал не йде.
            $this->finish($run, self::STATUS_FAILED, ['error' => 'exception', 'stopped' => $e::class . ': ' . mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $e->getMessage()) ?? '', 0, 200)]);
        }
    }

    /** @param array<string, mixed> $run */
    private function run(LanguageTarget $target, DateTimeImmutable $now, array $run): void
    {
        $langId = $target->langId;
        $dryRun = (bool) $run['dry_run'];
        $setupError = $this->setupError($target, $dryRun);
        if ($setupError !== null) {
            $this->finish($run, self::STATUS_FAILED, ['error' => $setupError]);
            return;
        }

        // Куди товари мови надсилались насправді. Мова й мітка — частина ID товару в Google: якщо вони
        // змінились, старі товари треба спершу прибрати (адмінка дозволяє це лише через вимкнення мови).
        $active = $this->settings->activeTarget($langId) ?? $target;
        if (!$target->removing && !$target->sameProductsAs($active)) {
            $this->finish($run, self::STATUS_FAILED, ['error' => 'target_changed']);
            return;
        }
        if (!$dryRun && !$target->removing && $active->dataSource !== $target->dataSource) {
            // Те саме мова~мітка~ID в іншому джерелі: insert переносить товар, тож шлемо все заново.
            $this->states->resetHashes($langId);
            $this->settings->setActiveTarget($langId, $target);
            $active = $target;
        }

        [$built, $warnings, $badIds] = $target->removing ? [[], [], []] : $this->build($target);
        $states = $this->states->load($langId);
        $this->forgetNeverSent($langId, $built, $states);
        if ($target->removing && $states === []) {
            // У Google від цієї мови нічого немає — вимикаємо одразу, і в сухому прогоні теж.
            $this->finishRemoval($langId);
            $this->finish($run + ['built' => 0, 'sent' => 0, 'deleted' => 0, 'failed' => 0], self::STATUS_OK, ['removed' => true]);
            return;
        }

        $confirmed = $this->settings->confirmedDeletes($langId);
        $plan = new SyncPlanner($this->settings->opsPerRun())->plan($built, $states, $now, $confirmed, $target->removing);
        $run += [
            'built' => count($built),
            'deferred' => $plan->deferred,
            'invalid' => count($plan->invalid) + count($badIds),
            'blocked_deletes' => $plan->blockedDeletes,
        ];
        $summary = [
            'skipped' => $target->removing ? [] : $this->items->lastSkipped(),
            'counts' => $plan->counts,
            'planned' => count($plan->operations),
            'warnings' => $warnings,
            'invalid' => array_slice($plan->invalid + $badIds, 0, self::SAMPLE, true),
        ];

        if ($plan->blockedDeletes !== null) {
            $this->settings->setPendingDeletes($langId, $plan->gone);
        } elseif ($plan->gone === [] && ($confirmed !== [] || $this->settings->pendingDeletes($langId) !== [])) {
            // Підтвердження живе, доки не видалено весь підтверджений набір: порції бюджету не вимагають нового.
            $this->settings->setConfirmedDeletes($langId, []);
            $this->settings->setPendingDeletes($langId, []);
        }

        if ($dryRun) {
            $this->finish($run + ['sent' => 0, 'deleted' => 0, 'failed' => 0], self::STATUS_DRY, $summary);
            return;
        }

        $client = $this->clients->create($this->credentials->load() ?? throw new \LogicException('key disappeared'), (string) $this->settings->accountId());
        $result = $this->execute($client, $target, $active, $plan, $states, $now);
        $this->settings->setActiveTarget($langId, $active);

        if ($target->removing && $result['stopped'] === null && $this->states->load($langId) === []) {
            $this->finishRemoval($langId);
        }

        $status = match (true) {
            $result['stopped'] !== null && $result['sent'] + $result['deleted'] === 0 => self::STATUS_FAILED,
            $result['stopped'] !== null, $result['failed'] > 0 => self::STATUS_PARTIAL,
            default => self::STATUS_OK,
        };
        $this->finish(
            $run + ['sent' => $result['sent'], 'deleted' => $result['deleted'], 'failed' => $result['failed']],
            $status,
            $summary + ['errors' => $result['errors'], 'stopped' => $result['stopped']],
        );
    }

    /**
     * Ключ — offerId самого запису (його бачить Google), а не ключ масиву збирача: розширення може
     * переписати g:id, і видалення тоді влучали б не в той товар.
     *
     * @return array{0: array<string, MappedItem>, 1: array<string, int>, 2: array<string, list<string>>}
     */
    private function build(LanguageTarget $target): array
    {
        $mapper = new ItemMapper($this->settings->priceLabel());
        $built = [];
        $warnings = [];
        $badIds = [];
        foreach ($this->items->build($target->langId, (string) $this->settings->siteUrl()) as $key => $feedItem) {
            $mapped = $mapper->fromFeedItem($feedItem, $target->contentLanguage, $target->feedLabel);
            if ($mapped->offerId === '' || strlen($mapped->offerId) > 64 || isset($built[$mapped->offerId])) {
                $badIds[(string) $key] = ['bad_offer_id'];
                continue;
            }
            $built[$mapped->offerId] = $mapped;
            foreach ($mapped->problems as $problem) {
                $warnings[$problem] = ($warnings[$problem] ?? 0) + 1;
            }
        }

        return [$built, $warnings, $badIds];
    }

    /**
     * @param array<string, ItemState> $states
     * @return array{sent: int, deleted: int, failed: int, errors: array<string, string>, stopped: ?string}
     */
    private function execute(MerchantClient $client, LanguageTarget $target, LanguageTarget $active, Plan $plan, array $states, DateTimeImmutable $now): array
    {
        $result = ['sent' => 0, 'deleted' => 0, 'failed' => 0, 'errors' => [], 'stopped' => null];
        $transientInRow = 0;
        $lastReason = null;
        $sameInRow = 0;

        foreach ($plan->operations as $operation) {
            try {
                if ($operation->type === Operation::INSERT) {
                    $client->insertProductInput((string) $target->dataSource, (array) $operation->productInput);
                    $this->states->markSent($target->langId, $operation->offerId, (string) $operation->fullHash, (string) $operation->criticalHash, $now);
                    $result['sent']++;
                } else {
                    // Видаляємо там, куди надсилали: при вимкненні мови це може бути вже не поточне джерело.
                    $client->deleteProductInput((string) $active->dataSource, $active->productInputId($operation->offerId));
                    $this->states->forget($target->langId, [$operation->offerId]);
                    $result['deleted']++;
                }
                $transientInRow = 0;
                $sameInRow = 0;
            } catch (AuthFailure $e) {
                $result['stopped'] = 'auth:' . $e->getReason();
                break;
            } catch (ApiError $e) {
                // Видалення того, чого в Google вже немає, — не помилка.
                if ($operation->type === Operation::DELETE && $e->status === 404) {
                    $this->states->forget($target->langId, [$operation->offerId]);
                    $result['deleted']++;
                    continue;
                }
                // Квота, ключ/доступ, 404 на вставці (джерела немає): далі слати немає сенсу.
                if ($e->isQuota() || $e->isFatal() || ($operation->type === Operation::INSERT && $e->status === 404)) {
                    $result['stopped'] = $e->describe();
                    break;
                }

                $attempts = ($states[$operation->offerId]->attempts ?? 0) + 1;
                $this->states->markFailed($target->langId, $operation->offerId, "{$e->status} {$e->reason}: {$e->getMessage()}", $attempts, self::nextAttempt($now, $attempts));
                $result['failed']++;
                if (count($result['errors']) < self::SAMPLE) {
                    $result['errors'][$operation->offerId] = "{$e->status} {$e->reason}";
                }

                $transientInRow = $e->isTransient() ? $transientInRow + 1 : 0;
                $sameInRow = $lastReason === $e->reason ? $sameInRow + 1 : 1;
                $lastReason = $e->reason;
                if ($e->status === 0 || $transientInRow >= self::TRANSIENT_STOP) {
                    $result['stopped'] = $e->describe();
                    break;
                }
                if ($sameInRow >= self::REPEATED_STOP) {
                    $result['stopped'] = 'repeated:' . $e->describe();
                    break;
                }
            }
        }

        return $result;
    }

    /** Мову вимкнено й усі її товари прибрано з Google: прибрати й саму мову з налаштувань. */
    private function finishRemoval(int $langId): void
    {
        $targets = $this->settings->languages();
        unset($targets[$langId]);
        $this->settings->setLanguages(array_values($targets));
        $this->settings->setActiveTarget($langId, null);
        $this->settings->setConfirmedDeletes($langId, []);
        $this->settings->setPendingDeletes($langId, []);
    }

    /** Що заважає запуску; сухий прогін потребує лише адреси сайту — щоб план можна було глянути до підключення Google. */
    private function setupError(LanguageTarget $target, bool $dryRun): ?string
    {
        if ($this->requirements->missing() !== []) {
            return 'requirements';
        }
        if ($this->settings->siteUrl() === null) {
            return 'site_url';
        }
        if ($dryRun) {
            return null;
        }

        return match (true) {
            $this->credentials->load() === null => 'key',
            $this->settings->accountId() === null => 'account_id',
            $target->dataSource === null && !$target->removing => 'data_source',
            default => null,
        };
    }

    /**
     * Рядки товарів, які жодного разу не дійшли до Google і зникли зі збірки: видаляти в Google нічого, лише стан.
     *
     * @param array<string, MappedItem> $built
     * @param array<string, ItemState> $states
     */
    private function forgetNeverSent(int $langId, array $built, array &$states): void
    {
        $forget = [];
        foreach ($states as $offerId => $state) {
            if ($state->sentAt === null && !isset($built[$offerId])) {
                $forget[] = (string) $offerId;
                unset($states[$offerId]);
            }
        }
        $this->states->forget($langId, $forget);
    }

    /** 15 хв, 30 хв, 1 год… не довше доби: помилку, яку виправили на сайті, наступна спроба має підхопити того ж дня. */
    private static function nextAttempt(DateTimeImmutable $now, int $attempts): DateTimeImmutable
    {
        $minutes = min(1440, 15 * (2 ** min($attempts - 1, 10)));

        return $now->modify("+{$minutes} minutes");
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $summary
     */
    private function finish(array $run, string $status, array $summary): void
    {
        $this->log->finish($this->runId, $run + [
            'status' => $status,
            'finished_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    }
}
