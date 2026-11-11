<?php

namespace Okay\Modules\Sviat\MerchantApi\Backend\Controllers;

use DateTimeImmutable;
use InvalidArgumentException;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogProducts;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiIssuesEntity;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiItemsEntity;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiRunsEntity;
use Okay\Modules\Sviat\MerchantApi\Google\ApiError;
use Okay\Modules\Sviat\MerchantApi\Google\AuthFailure;
use Okay\Modules\Sviat\MerchantApi\Google\InvalidServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClient;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClientFactory;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogStats;
use Okay\Modules\Sviat\MerchantApi\Services\CredentialStore;
use Okay\Modules\Sviat\MerchantApi\Services\FileSourceMatch;
use Okay\Modules\Sviat\MerchantApi\Services\LanguageTarget;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Services\Requirements;
use Okay\Modules\Sviat\MerchantApi\Http\TransportFailure;
use Okay\Modules\Sviat\MerchantApi\Mapping\PriceBucketLabel;
use Okay\Modules\Sviat\MerchantApi\Sync\StatusPoller;

class MerchantApiAdmin extends IndexAdmin
{
    // JSON-ключ сервісного акаунта — ~2,4 КБ; більший файл — точно не він.
    private const int MAX_KEY_BYTES = 16384;
    private const int RUNS_SHOWN = 20;
    private const int ISSUES_SHOWN = 300;
    // Таймаут задачі синхронізації в планувальнику (Init::registerSyncSchedules) і хвилина запасу.
    private const int RUN_TIMEOUT_SECONDS = 1860;
    /** Дія → секція сторінки, де показати її результат. */
    private const array SECTIONS = [
        'upload_key' => 'key',
        'delete_key' => 'key',
        'save_connection' => 'account',
        'test_connection' => 'access',
        'register_gcp' => 'access',
        'save_languages' => 'languages',
        'create_source' => 'languages',
        'poll_statuses' => 'sources',
        'save_price_label' => 'price_label',
        'save_catalog' => 'catalog',
        'save_sync' => 'sync',
        'confirm_deletes' => 'state',
    ];

    private ?string $section = null;

    private CredentialStore $credentials;
    private ModuleSettings $moduleSettings;
    private MerchantClientFactory $clients;

    public function fetch(
        CredentialStore $credentials,
        Requirements $requirements,
        ModuleSettings $moduleSettings,
        MerchantClientFactory $clients,
        EntityFactory $entityFactory,
        StatusPoller $statusPoller,
        CatalogStats $catalogStats,
        CatalogProducts $catalogProducts,
    ) {
        $this->credentials = $credentials;
        $this->moduleSettings = $moduleSettings;
        $this->clients = $clients;

        // Дія — лише з POST: на невалідному CSRF-токені ядро очищає $_POST, а $_FILES лишає.
        if ($this->request->method('post')) {
            $this->section = self::sectionOf((string) $this->request->post('action'));
            match ($this->request->post('action')) {
                'upload_key' => $this->uploadKey(),
                'delete_key' => $this->done('key_deleted', fn() => $credentials->delete()),
                'save_connection' => $this->saveConnection(),
                'test_connection' => $this->testConnection(),
                'register_gcp' => $this->registerGcp(),
                'save_languages' => $this->saveLanguages($entityFactory),
                'create_source' => $this->createSource(),
                'save_price_label' => $this->savePriceLabel(),
                'save_sync' => $this->saveSync(),
                'confirm_deletes' => $this->confirmDeletes(),
                // Під час переходу з файлу кількість товарів у джерелах потрібна зараз, а не о 06:40.
                'poll_statuses' => $this->done('statuses_polled', fn() => $statusPoller->poll(manual: true)),
                'save_catalog' => $this->saveCatalog(),
                default => null,
            };
        }

        $this->assignView($requirements, $entityFactory);
        $this->assignCatalog($entityFactory, $catalogStats, $catalogProducts);
        $this->response->setContent($this->design->fetch('merchant_api.tpl'));
    }

    private function uploadKey(): void
    {
        $file = $this->request->files('key_file');
        $path = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? (string) ($file['tmp_name'] ?? '') : '';
        if ($path === '' || !is_uploaded_file($path) || filesize($path) > self::MAX_KEY_BYTES) {
            $this->fail('key_file');
            return;
        }
        try {
            $this->credentials->saveJson((string) file_get_contents($path), new DateTimeImmutable());
        } catch (InvalidServiceAccountKey $e) {
            $this->fail('key_' . $e->getReason());
            return;
        }
        $this->done('key_saved');
    }

    private function saveConnection(): void
    {
        $accountId = trim((string) $this->request->post('account_id'));
        $siteUrl = trim((string) $this->request->post('site_url'));

        $accountOk = $this->moduleSettings->setAccountId($accountId === '' ? null : $accountId);
        $siteOk = $this->moduleSettings->setSiteUrl($siteUrl === '' ? null : $siteUrl);
        $this->moduleSettings->setCountries((string) $this->request->post('countries'));

        match (true) {
            !$accountOk => $this->fail('account_id'),
            !$siteOk => $this->fail('site_url'),
            default => $this->done('saved'),
        };
    }

    private function testConnection(): void
    {
        $this->withClient(function (MerchantClient $client): void {
            $sources = $client->listDataSources();
            $this->moduleSettings->setSourcesCache($sources, new DateTimeImmutable());
            $this->done('connection_ok');
        });
    }

    private function registerGcp(): void
    {
        $email = trim((string) $this->request->post('developer_email'));
        // Google вимагає людину-розробника, не сервісний акаунт.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with($email, '.gserviceaccount.com')) {
            $this->fail('developer_email');
            return;
        }
        $this->withClient(function (MerchantClient $client) use ($email): void {
            $client->registerGcp($email);
            $this->done('registered');
        });
    }

    /**
     * Мова й мітка — частина ID товару в Google. Поки в мови є надіслані товари, міняти їх не можна
     * (старі лишились би в Google), а вимкнення мови не прибирає її одразу: SyncRunner спершу
     * видаляє її товари з Google, потім саму мову.
     */
    private function saveLanguages(EntityFactory $entityFactory): void
    {
        $posted = $this->request->post('langs');
        $posted = is_array($posted) ? $posted : [];
        $current = $this->moduleSettings->languages();
        $sources = $this->apiSourcesByName();
        $items = $entityFactory->get(MerchantApiItemsEntity::class);
        $targets = [];
        $errors = [];

        foreach ($this->languages->getAllLanguages() as $language) {
            $langId = (int) $language->id;
            $row = is_array($posted[$langId] ?? null) ? $posted[$langId] : [];
            $was = $current[$langId] ?? null;
            $hasSent = $was !== null && $items->count(['lang_id' => $langId, 'sent' => 1]) > 0;

            if (($row['enabled'] ?? '') !== '1') {
                if ($was !== null && $hasSent) {
                    $targets[$langId] = $was->withRemoving(true);
                }
                continue;
            }

            $dataSource = trim((string) ($row['data_source'] ?? ''));
            // Порожній вибір при прив'язаному джерелі, якого просто немає в кеші списку, — не відв'язка.
            if ($dataSource === '' && $was?->dataSource !== null && !isset($sources[$was->dataSource])) {
                $dataSource = $was->dataSource;
            }
            $target = LanguageTarget::create(
                $langId,
                self::contentLanguage($language),
                strtoupper(trim((string) ($row['feed_label'] ?? ''))),
                $dataSource === '' ? null : $dataSource,
            );
            $error = match (true) {
                $target === null => 'invalid',
                // Мова й мітка API-джерела незмінні: прив'язка до джерела з іншими зламала б кожну вставку.
                $target->dataSource !== null && $target->dataSource !== $was?->dataSource && !self::sourceMatches($sources[$target->dataSource] ?? null, $target) => 'source',
                $hasSent && !$target->sameProductsAs($was) => 'locked',
                default => null,
            };
            if ($error !== null) {
                $errors[] = (string) $language->label;
                if ($was !== null) {
                    $targets[$langId] = $was;
                }
                continue;
            }
            $targets[$langId] = $target;
        }

        // Дві мови з однаковими мовою й міткою — це ті самі товари в Google: перезаписували б одна одну.
        $identities = [];
        foreach ($targets as $langId => $target) {
            $identity = "{$target->contentLanguage}~{$target->feedLabel}";
            if (isset($identities[$identity])) {
                $errors[] = $identity;
                $targets[$langId] = $current[$langId] ?? null;
            }
            $identities[$identity] = true;
        }

        $this->moduleSettings->setLanguages(array_values(array_filter($targets)));
        if ($errors !== []) {
            $this->design->assign('ma_error_langs', $errors);
            $this->fail('languages');
            return;
        }
        $this->done('saved');
    }

    private function createSource(): void
    {
        $langId = (int) $this->request->post('lang_id');
        $targets = $this->moduleSettings->languages();
        $target = $targets[$langId] ?? null;
        if ($target === null || $target->dataSource !== null) {
            $this->fail('create_source');
            return;
        }
        $fileSource = FileSourceMatch::forLanguage($this->moduleSettings->sourcesCache()['sources'], $target->contentLanguage);
        $this->withClient(function (MerchantClient $client) use ($target, $targets, $fileSource): void {
            $name = trim((string) $this->settings->get('site_name')) . ' API ' . strtoupper($target->contentLanguage);
            $created = $client->createApiDataSource($name, $target->contentLanguage, $target->feedLabel, $this->moduleSettings->countries(), $fileSource['destinations'] ?? []);
            $sourceName = (string) ($created['name'] ?? '');
            $bound = LanguageTarget::create($target->langId, $target->contentLanguage, $target->feedLabel, $sourceName);
            if ($bound === null) {
                $this->fail('create_source');
                return;
            }
            // Спершу прив'язка, тоді кеш: збій читання списку не має лишити створене джерело неприв'язаним.
            $targets[$target->langId] = $bound;
            $this->moduleSettings->setLanguages(array_values($targets));
            try {
                $this->moduleSettings->setSourcesCache($client->listDataSources(), new DateTimeImmutable());
            } catch (ApiError | AuthFailure | TransportFailure) {
            }
            // Google показує нове джерело у списку із затримкою — без цього прив'язка виглядала б хибною.
            $this->moduleSettings->rememberSource($created);
            $this->done('source_created');
        });
    }

    private function savePriceLabel(): void
    {
        if ($this->request->post('price_label_enabled') !== '1') {
            $this->moduleSettings->setPriceLabel(null);
            $this->done('saved');
            return;
        }
        $index = (int) $this->request->post('price_label_index');
        $bounds = trim((string) $this->request->post('price_label_bounds'));
        try {
            $label = new PriceBucketLabel($index, PriceBucketLabel::parseBounds($bounds) ?? []);
        } catch (InvalidArgumentException) {
            $this->fail('price_label');
            return;
        }
        $this->moduleSettings->setPriceLabel($label, $bounds);
        $this->done('saved');
    }

    /** Один режим замість двох перемикачів: «пауза + сухий прогін» не мають четвертої осмисленої комбінації. */
    private function saveSync(): void
    {
        $mode = $this->request->post('mode');
        if (!in_array($mode, ['paused', 'dry', 'live'], true)) {
            $this->fail('mode');
            return;
        }
        $this->moduleSettings->setPaused($mode === 'paused');
        $this->moduleSettings->setDryRun($mode !== 'live');
        $this->moduleSettings->setOpsPerRun((int) $this->request->post('ops_per_run'));
        $this->done('saved');
    }

    /**
     * Підтверджується набір, який зупинив останній запуск мови (людина бачила саме цю кількість).
     * Запуски видалятимуть його порціями, доки зниклі товари — його підмножина.
     */
    private function confirmDeletes(): void
    {
        $langId = (int) $this->request->post('lang_id');
        $pending = $this->moduleSettings->pendingDeletes($langId);
        if ($pending === [] || count($pending) !== (int) $this->request->post('count')) {
            $this->fail('confirm_deletes');
            return;
        }
        $this->moduleSettings->setConfirmedDeletes($langId, $pending);
        $this->done('deletes_confirmed');
    }

    /** @param callable(MerchantClient): void $action */
    private function withClient(callable $action): void
    {
        $key = $this->credentials->load();
        $accountId = $this->moduleSettings->accountId();
        if ($key === null || $accountId === null) {
            $this->fail('not_connected');
            return;
        }
        try {
            $action($this->clients->create($key, $accountId));
        } catch (ApiError $e) {
            $this->design->assign('ma_google_error', "{$e->status} {$e->reason}: {$e->getMessage()}");
            $this->fail('google');
        } catch (AuthFailure $e) {
            $this->design->assign('ma_google_error', 'OAuth: ' . $e->getReason());
            $this->fail('google');
        } catch (TransportFailure) {
            $this->fail('network');
        }
    }

    private function saveCatalog(): void
    {
        $fields = ['mode', 'include_categories', 'include_brands', 'include_products', 'exclude_categories', 'exclude_brands',
            'exclude_products', 'send_out_of_stock', 'description', 'min_price', 'detail_feature_ids'];
        // Request::post() без імені віддає сире тіло запиту, а не масив полів.
        $catalog = CatalogSettings::fromForm(array_combine($fields, array_map(fn(string $field) => $this->request->post($field), $fields)));
        if ($catalog === null) {
            $this->fail('catalog');
            return;
        }
        $catalog->toSettings($this->settings);
        $this->done('saved');
    }

    private function done(string $message, ?callable $action = null): void
    {
        if ($action !== null) {
            $action();
        }
        // Секція їде разом із повідомленням: після редиректу POST уже немає, а показати треба біля дії.
        $this->postRedirectGet->storeMessageSuccess($this->section === null ? $message : "{$this->section}:{$message}");
        $this->postRedirectGet->redirect();
    }

    private function fail(string $message): void
    {
        $this->design->assign('message_error', $message);
        $this->design->assign('ma_message_section', $this->section);
    }

    /** @return array{0: ?string, 1: string} секція (або null — показати вгорі) і ключ повідомлення */
    public static function splitMessage(string $stored): array
    {
        [$section, $key] = str_contains($stored, ':') ? explode(':', $stored, 2) : [null, $stored];

        return in_array($section, self::SECTIONS, true) ? [$section, $key] : [null, $stored];
    }

    public static function sectionOf(string $action): ?string
    {
        return self::SECTIONS[$action] ?? null;
    }

    private function assignView(Requirements $requirements, EntityFactory $entityFactory): void
    {
        $stored = $this->design->getVar('message_success');
        if (is_string($stored) && $stored !== '') {
            [$section, $key] = self::splitMessage($stored);
            $this->design->assign('message_success', $key);
            $this->design->assign('ma_message_section', $section);
        }

        $targets = $this->moduleSettings->languages();
        $sources = $this->moduleSettings->sourcesCache();
        $apiSources = $this->apiSourcesByName();
        $itemsEntity = $entityFactory->get(MerchantApiItemsEntity::class);
        $runsEntity = $entityFactory->get(MerchantApiRunsEntity::class);

        $languages = [];
        foreach ($this->languages->getAllLanguages() as $language) {
            $langId = (int) $language->id;
            $target = $targets[$langId] ?? null;
            $fileSource = FileSourceMatch::forLanguage($sources['sources'], self::contentLanguage($language));
            $languages[] = [
                'id' => $langId,
                'label' => (string) $language->label,
                'name' => (string) ($language->name ?? $language->label),
                'content_language' => self::contentLanguage($language),
                'target' => $target?->toArray(),
                'source_ok' => $target?->dataSource === null || self::sourceMatches($apiSources[$target->dataSource] ?? null, $target),
                'items' => $target === null ? 0 : $itemsEntity->count(['lang_id' => $langId]),
                'sent' => $target === null ? 0 : $itemsEntity->count(['lang_id' => $langId, 'sent' => 1]),
                'failed' => $target === null ? 0 : $itemsEntity->count(['lang_id' => $langId, 'failed' => 1]),
                'blocked_deletes' => count($this->moduleSettings->pendingDeletes($langId)) ?: null,
                'removing' => $target !== null && $target->removing,
                'source_cached' => $target?->dataSource === null || isset($apiSources[$target->dataSource]),
                'file_source' => $fileSource,
                // Мітка, що розходиться з чинним файловим джерелом, створить у Google нові товари поруч зі старими.
                'label_differs' => $fileSource !== null && $target !== null && !$target->removing && $fileSource['feed_label'] !== null && $fileSource['feed_label'] !== $target->feedLabel,
            ];
        }

        $runs = [];
        $staleBefore = time() - self::RUN_TIMEOUT_SECONDS;
        foreach ($runsEntity->find(['limit' => self::RUNS_SHOWN]) as $run) {
            $summary = json_decode((string) $run->summary, true);
            $run->summary_data = is_array($summary) ? $summary : [];
            // «running» без завершення довше за таймаут — процес обірвано, рядок уже ніхто не оновить.
            if ($run->status === 'running' && strtotime((string) $run->started_at) < $staleBefore) {
                $run->status = 'interrupted';
            }
            $run->stopped_kind = self::stoppedKind((string) ($run->summary_data['stopped'] ?? ''));
            $runs[] = $run;
        }

        $issues = $entityFactory->get(MerchantApiIssuesEntity::class)->find(['limit' => self::ISSUES_SHOWN]);
        $productIds = [];
        $variantIds = array_values(array_filter(array_map(static fn($i) => (int) $i->offer_id, $issues)));
        if ($variantIds !== []) {
            foreach ($entityFactory->get(VariantsEntity::class)->cols(['id', 'product_id'])->noLimit()->find(['id' => $variantIds]) as $variant) {
                $productIds[(int) $variant->id] = (int) $variant->product_id;
            }
        }
        foreach ($issues as $issue) {
            $decoded = json_decode((string) $issue->issues, true);
            $issue->issues_list = is_array($decoded) ? $decoded : [];
            $issue->product_id = $productIds[(int) $issue->offer_id] ?? null;
        }

        $this->design->assign('ma_missing', $requirements->missing());
        $this->design->assign('ma_key', $this->credentials->summary());
        $this->design->assign('ma_account_id', (string) $this->moduleSettings->accountId());
        $this->design->assign('ma_site_url', $this->moduleSettings->siteUrl() ?? rtrim(Request::getRootUrl(), '/'));
        $this->design->assign('ma_site_url_saved', $this->moduleSettings->siteUrl() !== null);
        $this->design->assign('ma_countries', implode(', ', $this->moduleSettings->countries()));
        $this->design->assign('ma_languages', $languages);
        $this->design->assign('ma_lang_names', array_column($languages, 'name', 'id'));
        $this->design->assign('ma_sources', $sources['sources']);
        $this->design->assign('ma_sources_fetched_at', $sources['fetched_at']);
        $this->design->assign('ma_price_label', $this->moduleSettings->priceLabelRaw());
        // Цінову мітку файлових джерел зазвичай ставить правило Merchant Center, а на API-джерело воно не діє.
        $this->design->assign('ma_price_label_warning', $this->moduleSettings->priceLabel() === null && array_filter(array_column($languages, 'file_source')) !== []);
        $this->design->assign('ma_source_counts', is_array($this->moduleSettings->statusSummary()['by_source'] ?? null) ? $this->moduleSettings->statusSummary()['by_source'] : []);
        $this->design->assign('ma_paused', $this->moduleSettings->isPaused());
        $this->design->assign('ma_dry_run', $this->moduleSettings->isDryRun());
        $this->design->assign('ma_ops_per_run', $this->moduleSettings->opsPerRun());
        $this->design->assign('ma_runs', $runs);
        $this->design->assign('ma_status', $this->moduleSettings->statusSummary());
        $this->design->assign('ma_mode', $this->moduleSettings->isPaused() ? 'paused' : ($this->moduleSettings->isDryRun() ? 'dry' : 'live'));
        $this->design->assign('ma_steps', $this->steps($languages, $sources['fetched_at']));
        $this->design->assign('ma_issues', $issues);
    }

    private function assignCatalog(EntityFactory $entityFactory, CatalogStats $catalogStats, CatalogProducts $catalogProducts): void
    {
        $catalog = CatalogSettings::fromSettings($this->settings);
        $lastSkipped = null;
        foreach ($entityFactory->get(MerchantApiRunsEntity::class)->find(['limit' => self::RUNS_SHOWN]) as $run) {
            $summary = json_decode((string) $run->summary, true);
            if ($run->status !== 'running' && is_array($summary['skipped'] ?? null)) {
                $lastSkipped = $summary['skipped'];
                break;
            }
        }

        $products = $catalogProducts->byId(array_merge($catalog->includeProducts, $catalog->excludeProducts));
        $pick = static fn(array $ids) => array_values(array_filter(array_map(static fn($id) => $products[$id] ?? null, $ids)));

        $this->design->assign('ma_catalog', $catalog);
        $this->design->assign('ma_catalog_stats', $catalogStats->summarize($catalog, $lastSkipped));
        $this->design->assign('ma_run_reasons', CatalogStats::RUN_REASONS);
        $this->design->assign('ma_catalog_categories', $entityFactory->get(CategoriesEntity::class)->getCategoriesTree());
        $this->design->assign('ma_catalog_brands', $entityFactory->get(BrandsEntity::class)->noLimit()->find());
        $this->design->assign('ma_catalog_features', $entityFactory->get(FeaturesEntity::class)->noLimit()->find());
        $this->design->assign('ma_catalog_include_products', $pick($catalog->includeProducts));
        $this->design->assign('ma_catalog_exclude_products', $pick($catalog->excludeProducts));
    }

    /**
     * Порядок налаштування з першого разу: що зроблено й куди йти далі.
     *
     * @param list<array<string, mixed>> $languages
     * @return list<array{key: string, done: bool, tab: string}>
     */
    private function steps(array $languages, ?string $sourcesFetchedAt): array
    {
        $bound = array_filter($languages, static fn(array $l) => $l['target'] !== null && $l['target']['data_source'] !== null && !$l['removing']);

        return [
            ['key' => 'key', 'done' => $this->credentials->summary() !== null, 'tab' => 'ma_connection'],
            ['key' => 'account', 'done' => $this->moduleSettings->accountId() !== null && $this->moduleSettings->siteUrl() !== null, 'tab' => 'ma_connection'],
            // Список джерел читається лише після реєстрації проєкту, тож успішне читання підтверджує обидва кроки.
            ['key' => 'access', 'done' => $sourcesFetchedAt !== null, 'tab' => 'ma_connection'],
            ['key' => 'languages', 'done' => $bound !== [], 'tab' => 'ma_languages'],
            ['key' => 'live', 'done' => !$this->moduleSettings->isPaused() && !$this->moduleSettings->isDryRun(), 'tab' => 'ma_sync'],
        ];
    }

    /** @return array<string, array<string, mixed>> лише API-джерела (в інші Google не дає писати) */
    private function apiSourcesByName(): array
    {
        $byName = [];
        foreach ($this->moduleSettings->sourcesCache()['sources'] as $source) {
            if ($source['input'] === 'API' && $source['primary']) {
                $byName[$source['name']] = $source;
            }
        }

        return $byName;
    }

    /** @param array<string, mixed>|null $source */
    private static function sourceMatches(?array $source, LanguageTarget $target): bool
    {
        if ($source === null) {
            return false;
        }
        // Джерело без мови й мітки приймає будь-які; з ними — лише свою пару.
        return ($source['content_language'] ?? null) === null
            || ($source['content_language'] === $target->contentLanguage && ($source['feed_label'] ?? null) === $target->feedLabel);
    }

    /** До якої підказки веде причина зупинки: доступ і реєстрація чи квота. */
    private static function stoppedKind(string $stopped): ?string
    {
        return match (true) {
            preg_match('/\A(401|403):|\Aauth:/', $stopped) === 1 => 'access',
            str_starts_with($stopped, '429:') => 'quota',
            default => null,
        };
    }

    private static function contentLanguage(object $language): string
    {
        return strtolower(substr((string) ($language->href_lang ?? ''), 0, 2));
    }
}
