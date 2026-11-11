<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Mapping\PriceBucketLabel;

/**
 * Налаштування модуля, крім ключа (його тримає CredentialStore).
 *
 * Зберігається лише перевірене, а читається з перевіркою ще раз: ядро десеріалізує кожне значення,
 * тож рядок у полі адмінки може повернутись об'єктом — і (string) поклав би сторінку й задачі.
 */
class ModuleSettings
{
    public const string PREFIX = 'sviat__merchant_api__';

    /** Скільки операцій API за один запуск задачі (15 хв) на мову; решта — наступним запуском. */
    public const int DEFAULT_OPS_PER_RUN = 500;

    private const string SITE_URL = '#\Ahttps?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~%/-]*)?\z#';

    public function __construct(private Settings $settings)
    {
    }

    /** ID акаунта Merchant Center — лише цифри (іде в шлях запиту). */
    public function accountId(): ?string
    {
        $value = $this->string('account_id');

        return preg_match('/\A[1-9]\d{0,19}\z/', $value) ? $value : null;
    }

    /** @return bool false — значення не прийнято, збережено порожнє */
    public function setAccountId(?string $accountId): bool
    {
        $valid = $accountId !== null && preg_match('/\A[1-9]\d{0,19}\z/', $accountId);
        $this->settings->set(self::PREFIX . 'account_id', $valid ? $accountId : '');

        return $valid || $accountId === null;
    }

    /** Корінь сайту для посилань у CLI (https://host[:port][/subdir]). */
    public function siteUrl(): ?string
    {
        $value = $this->string('site_url');

        return preg_match(self::SITE_URL, $value) ? rtrim($value, '/') : null;
    }

    /** @return bool false — значення не прийнято, збережено порожнє */
    public function setSiteUrl(?string $siteUrl): bool
    {
        $valid = $siteUrl !== null && preg_match(self::SITE_URL, $siteUrl);
        $this->settings->set(self::PREFIX . 'site_url', $valid ? rtrim($siteUrl, '/') : '');

        return $valid || $siteUrl === null;
    }

    /**
     * Обрані мови: lang_id → як передавати.
     *
     * @return array<int, LanguageTarget>
     */
    public function languages(): array
    {
        $stored = $this->settings->get(self::PREFIX . 'languages');
        $targets = [];
        foreach (is_array($stored) ? $stored : [] as $row) {
            $target = is_array($row) ? LanguageTarget::fromArray($row) : null;
            if ($target !== null) {
                $targets[$target->langId] = $target;
            }
        }

        return $targets;
    }

    /** @param list<LanguageTarget> $targets */
    public function setLanguages(array $targets): void
    {
        $this->settings->set(self::PREFIX . 'languages', array_map(static fn(LanguageTarget $t) => $t->toArray(), $targets));
    }

    /**
     * Конфігурація мови, з якою товари востаннє реально надсилались. Відрізняється від поточної —
     * значить, у Google товари лежать в іншому джерелі, і їх треба перенести (див. SyncRunner).
     */
    public function activeTarget(int $langId): ?LanguageTarget
    {
        $stored = $this->settings->get(self::PREFIX . "active_target_{$langId}");

        return is_array($stored) ? LanguageTarget::fromArray($stored) : null;
    }

    public function setActiveTarget(int $langId, ?LanguageTarget $target): void
    {
        $this->settings->set(self::PREFIX . "active_target_{$langId}", $target?->toArray() ?? '');
    }

    public function priceLabel(): ?PriceBucketLabel
    {
        $index = $this->string('price_label_index');
        $bounds = PriceBucketLabel::parseBounds($this->string('price_label_bounds'));
        if (!ctype_digit($index) || $bounds === null) {
            return null;
        }
        try {
            return new PriceBucketLabel((int) $index, $bounds);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{index: string, bounds: string} як збережено — для форми */
    public function priceLabelRaw(): array
    {
        return ['index' => $this->string('price_label_index'), 'bounds' => $this->string('price_label_bounds')];
    }

    /** Лише перевірене PriceBucketLabel — інакше вимкнено. */
    public function setPriceLabel(?PriceBucketLabel $label, string $bounds = ''): void
    {
        $this->settings->set(self::PREFIX . 'price_label_index', $label === null ? '' : (string) $label->labelIndex);
        $this->settings->set(self::PREFIX . 'price_label_bounds', $label === null ? '' : $bounds);
    }

    /** Пауза — типово УВІМКНЕНА: на копії прод-бази модуль не має сам почати слати в Google. */
    public function isPaused(): bool
    {
        return $this->string('paused') !== '0';
    }

    public function setPaused(bool $paused): void
    {
        $this->settings->set(self::PREFIX . 'paused', $paused ? '1' : '0');
    }

    /** Сухий прогін — типово увімкнений: план рахується й показується, у Google нічого не йде. */
    public function isDryRun(): bool
    {
        return $this->string('dry_run') !== '0';
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->settings->set(self::PREFIX . 'dry_run', $dryRun ? '1' : '0');
    }

    public function opsPerRun(): int
    {
        $value = $this->string('ops_per_run');

        return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 5000 ? (int) $value : self::DEFAULT_OPS_PER_RUN;
    }

    public function setOpsPerRun(int $ops): void
    {
        $this->settings->set(self::PREFIX . 'ops_per_run', (string) max(1, min(5000, $ops)));
    }

    /**
     * Видалення, які запобіжник зупинив останнім запуском мови (ID товарів), — їх людина й підтверджує.
     * Окремий ключ на мову: процеси мов ідуть паралельно, а спільний масив переписували б один одному.
     *
     * @return list<string>
     */
    public function pendingDeletes(int $langId): array
    {
        return $this->idList("pending_deletes_{$langId}");
    }

    /** @param list<string> $offerIds */
    public function setPendingDeletes(int $langId, array $offerIds): void
    {
        $this->settings->set(self::PREFIX . "pending_deletes_{$langId}", $offerIds);
    }

    /**
     * Підтверджений набір: видалення дозволені, поки зниклі товари — його підмножина. Тож порція
     * за порцією (бюджет запуску) не вимагає нового підтвердження, а інший набір — вимагає.
     *
     * @return list<string>
     */
    public function confirmedDeletes(int $langId): array
    {
        return $this->idList("confirmed_deletes_{$langId}");
    }

    /** @param list<string> $offerIds */
    public function setConfirmedDeletes(int $langId, array $offerIds): void
    {
        $this->settings->set(self::PREFIX . "confirmed_deletes_{$langId}", $offerIds);
    }

    /** Країни показу для нових API-джерел (CLDR, напр. UA). */
    public function countries(): array
    {
        $codes = self::parseCountries($this->string('countries'));

        return $codes === [] ? ['UA'] : $codes;
    }

    public function setCountries(string $countries): void
    {
        $this->settings->set(self::PREFIX . 'countries', implode(',', self::parseCountries($countries)));
    }

    /**
     * Джерела даних, прочитані з Google останнього разу (для вибору в адмінці без запиту на кожне відкриття).
     *
     * @return array{fetched_at: ?string, sources: list<array{name: string, display_name: string, input: string, content_language: ?string, feed_label: ?string, countries: list<string>, primary: bool, destinations: list<array{destination: string, state: string}>}>}
     */
    public function sourcesCache(): array
    {
        $stored = $this->settings->get(self::PREFIX . 'sources_cache');
        $stored = is_array($stored) ? $stored : [];

        $sources = [];
        foreach (is_array($stored['sources'] ?? null) ? $stored['sources'] : [] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !preg_match('~\Aaccounts/\d+/dataSources/\d+\z~', $row['name'])) {
                continue;
            }
            $sources[] = [
                'name' => $row['name'],
                'display_name' => is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
                'input' => is_string($row['input'] ?? null) ? $row['input'] : '',
                'content_language' => is_string($row['content_language'] ?? null) ? $row['content_language'] : null,
                'feed_label' => is_string($row['feed_label'] ?? null) ? $row['feed_label'] : null,
                'countries' => array_values(array_filter((array) ($row['countries'] ?? []), is_string(...))),
                'primary' => ($row['primary'] ?? false) === true,
                'destinations' => self::destinations($row['destinations'] ?? null),
            ];
        }

        return [
            'fetched_at' => is_string($stored['fetched_at'] ?? null) ? $stored['fetched_at'] : null,
            'sources' => $sources,
        ];
    }

    /** @param list<array<string, mixed>> $sources ресурси DataSource з API */
    public function setSourcesCache(array $sources, \DateTimeImmutable $fetchedAt): void
    {
        $rows = [];
        foreach ($sources as $source) {
            $name = is_string($source['name'] ?? null) ? $source['name'] : '';
            if (!preg_match('~\Aaccounts/\d+/dataSources/\d+\z~', $name)) {
                continue;
            }
            $primary = is_array($source['primaryProductDataSource'] ?? null) ? $source['primaryProductDataSource'] : null;
            $rows[] = [
                'name' => $name,
                'display_name' => mb_substr(is_string($source['displayName'] ?? null) ? $source['displayName'] : '', 0, 100),
                'input' => mb_substr(is_string($source['input'] ?? null) ? $source['input'] : '', 0, 20),
                'content_language' => is_string($primary['contentLanguage'] ?? null) ? $primary['contentLanguage'] : null,
                'feed_label' => is_string($primary['feedLabel'] ?? null) ? $primary['feedLabel'] : null,
                'countries' => array_values(array_filter((array) ($primary['countries'] ?? []), is_string(...))),
                'primary' => $primary !== null,
                'destinations' => self::destinations($primary['destinations'] ?? null),
            ];
        }
        $this->settings->set(self::PREFIX . 'sources_cache', ['fetched_at' => $fetchedAt->format(DATE_ATOM), 'sources' => $rows]);
    }

    /**
     * Додає одне джерело до кешу (ресурс DataSource з відповіді Google). Після dataSources.create Google віддає нове
     * джерело в списку не одразу, і без цього адмінка вважала б прив'язку хибною.
     *
     * @param array<string, mixed> $source
     */
    public function rememberSource(array $source): void
    {
        $cache = $this->settings->get(self::PREFIX . 'sources_cache');
        $cache = is_array($cache) ? $cache : [];
        $raw = [];
        foreach ($this->sourcesCache()['sources'] as $row) {
            if ($row['name'] !== ($source['name'] ?? null)) {
                $raw[] = ['name' => $row['name'], 'displayName' => $row['display_name'], 'input' => $row['input'], 'primaryProductDataSource' => $row['primary']
                    ? ['contentLanguage' => $row['content_language'], 'feedLabel' => $row['feed_label'], 'countries' => $row['countries'], 'destinations' => $row['destinations']]
                    : null];
            }
        }
        $raw[] = $source;
        $fetchedAt = is_string($cache['fetched_at'] ?? null) ? new \DateTimeImmutable($cache['fetched_at']) : new \DateTimeImmutable();
        $this->setSourcesCache($raw, $fetchedAt);
    }

    /** @return array<string, mixed> */
    public function statusSummary(): array
    {
        $stored = $this->settings->get(self::PREFIX . 'status_summary');

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, mixed> $summary */
    public function setStatusSummary(array $summary): void
    {
        $this->settings->set(self::PREFIX . 'status_summary', $summary);
    }

    /**
     * Способи показу джерела ({destination, state}) — лише ті, що схожі на значення Google.
     *
     * @return list<array{destination: string, state: string}>
     */
    private static function destinations(mixed $raw): array
    {
        $rows = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            $destination = is_array($row) ? ($row['destination'] ?? null) : null;
            $state = is_array($row) ? ($row['state'] ?? null) : null;
            if (is_string($destination) && preg_match('/\A[A-Z_]{1,40}\z/', $destination) && in_array($state, ['ENABLED', 'DISABLED'], true)) {
                $rows[] = ['destination' => $destination, 'state' => $state];
            }
        }

        return $rows;
    }

    private function string(string $name): string
    {
        $value = $this->settings->get(self::PREFIX . $name);

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private function idList(string $name): array
    {
        $stored = $this->settings->get(self::PREFIX . $name);

        return array_values(array_filter(is_array($stored) ? $stored : [], static fn($id) => is_string($id) && $id !== '' && strlen($id) <= 64));
    }

    /** @return list<string> */
    private static function parseCountries(string $input): array
    {
        $codes = preg_split('/[\s,;]+/', strtoupper($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($codes, static fn(string $c) => (bool) preg_match('/\A[A-Z]{2}\z/', $c))));
    }
}
