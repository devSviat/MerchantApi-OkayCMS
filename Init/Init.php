<?php

namespace Okay\Modules\Sviat\MerchantApi\Init;

use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Entities\CategoriesEntity;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiIssuesEntity;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiItemsEntity;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiRunsEntity;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use Okay\Modules\Sviat\MerchantApi\Sync\StatusPoller;
use Okay\Modules\Sviat\MerchantApi\Sync\SyncRunner;

class Init extends AbstractInit
{
    public const string PERMISSION = 'sviat__merchant_api';

    public function install()
    {
        $this->setBackendMainController('MerchantApiAdmin');

        $langId = new EntityField('lang_id')->setTypeInt(11, false);
        $offerId = new EntityField('offer_id')->setTypeVarchar(64, false);
        $this->migrateEntityTable(MerchantApiItemsEntity::class, [
            new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            $langId,
            $offerId->setIndexUnique(null, $langId),
            new EntityField('full_hash')->setTypeVarchar(40),
            new EntityField('critical_hash')->setTypeVarchar(40),
            new EntityField('sent_at')->setTypeDatetime(),
            new EntityField('attempts')->setTypeInt(11, false)->setDefault(0),
            new EntityField('next_attempt_at')->setTypeDatetime(),
            new EntityField('last_error')->setTypeVarchar(400),
        ]);

        $this->migrateEntityTable(MerchantApiRunsEntity::class, [
            new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            new EntityField('lang_id')->setTypeInt(11, false)->setIndex(),
            new EntityField('started_at')->setTypeDatetime(false),
            new EntityField('finished_at')->setTypeDatetime(),
            new EntityField('status')->setTypeVarchar(16, false),
            new EntityField('dry_run')->setTypeTinyInt(1, false)->setDefault(1),
            new EntityField('built')->setTypeInt(11)->setNullable(),
            new EntityField('sent')->setTypeInt(11)->setNullable(),
            new EntityField('deleted')->setTypeInt(11)->setNullable(),
            new EntityField('failed')->setTypeInt(11)->setNullable(),
            new EntityField('deferred')->setTypeInt(11)->setNullable(),
            new EntityField('invalid')->setTypeInt(11)->setNullable(),
            new EntityField('blocked_deletes')->setTypeInt(11)->setNullable(),
            new EntityField('summary')->setTypeText()->setNullable(),
        ]);

        $this->migrateEntityTable(MerchantApiIssuesEntity::class, [
            new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            new EntityField('content_language')->setTypeVarchar(8, false),
            new EntityField('feed_label')->setTypeVarchar(20, false),
            new EntityField('offer_id')->setTypeVarchar(64, false)->setIndex(),
            new EntityField('severity')->setTypeVarchar(16, false),
            new EntityField('issues')->setTypeText()->setNullable(),
            new EntityField('polled_at')->setTypeDatetime(false),
        ]);
    }

    /**
     * 2.0: модуль сам вибирає товари. Вибір і два налаштування один раз переносяться з OkayCMS/GoogleMerchant,
     * щоб у Google пішло те саме, що й до оновлення; збережений вибір модуля не перезаписується.
     */
    public function update_2_0_0(): void
    {
        $locator = ServiceLocator::getInstance();
        $settings = $locator->getService(Settings::class);
        $queryFactory = $locator->getService(QueryFactory::class);
        if (CatalogSettings::isStored($settings) || !self::tableExists($queryFactory, 'okaycms__google_merchant__relations')) {
            return;
        }

        $relations = $queryFactory->newSelect()
            ->from('__okaycms__google_merchant__relations')
            ->cols(['feed_id', 'entity_type', 'entity_id', 'include'])
            ->results();
        $gmSettings = [];
        foreach (['okaycms__google_merchant__upload_non_exists_products_to_google', 'okaycms__google_merchant__use_full_description_to_google'] as $name) {
            $gmSettings[$name] = $settings->get($name);
        }
        $allCategories = (int) $locator->getService(EntityFactory::class)->get(CategoriesEntity::class)->count();

        CatalogSettings::fromGoogleMerchant(array_map(static fn(object $row) => (array) $row, $relations), $gmSettings, $allCategories)
            ->toSettings($settings);
    }

    public function init()
    {
        $this->addPermission(self::PERMISSION);
        $this->registerBackendController('MerchantApiAdmin');
        $this->addBackendControllerPermission('MerchantApiAdmin', self::PERMISSION);
        // Іконка brand-google з Tabler Icons (MIT), https://tabler.io/icons/icon/brand-google
        $this->extendBackendMenu('sviat__merchant_api__menu', [
            'sviat__merchant_api__menu' => ['MerchantApiAdmin'],
        ], '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.945 11a9 9 0 1 1 -3.284 -5.997l-2.655 2.392a5.5 5.5 0 1 0 2.119 6.605h-4.125v-3h7.945z"/></svg>');

        $this->registerSyncSchedules();
        $this->registerSchedule(
            new Schedule([StatusPoller::class, 'poll'])
                ->name('MerchantApi: product statuses from Merchant Center')
                ->time('40 6 * * *')
                ->overlap(false)
                ->timeout(1800)
        );
    }

    /**
     * Задача на кожну мову сайту, а не лише на обрані: планувальник адресує задачу порядковим номером,
     * і дочірній процес `scheduler:task N` мусить зареєструвати той самий набір. Залежність кількості задач
     * від налаштувань зсунула б номери, щойно налаштування змінились між батьківським і дочірнім процесом.
     * Необрана мова — задача нічого не робить.
     */
    private function registerSyncSchedules(): void
    {
        // Init не має DI; Languages — уже створений сервіс ядра.
        $languages = ServiceLocator::getInstance()->getService(Languages::class)->getAllLanguages();
        foreach (array_keys($languages) as $langId) {
            $langId = (int) $langId;
            $this->registerSchedule(
                new Schedule(static function (SyncRunner $runner) use ($langId): void {
                    $runner->runLanguage($langId);
                })
                    ->name("MerchantApi: sync language {$langId}")
                    ->time('*/15 * * * *')
                    ->overlap(false)
                    ->timeout(1800)
            );
        }
    }

    /** information_schema, а не пробний SELECT: помилка запиту до відсутньої таблиці потрапила б у лог як ERROR. */
    private static function tableExists(QueryFactory $queryFactory, string $table): bool
    {
        return (int) $queryFactory->newSqlQuery()
            ->setStatement('SELECT COUNT(*) AS count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table')
            ->bindValue('table', self::prefixed($table))
            ->result('count') > 0;
    }

    private static function prefixed(string $table): string
    {
        $prefix = (string) ServiceLocator::getInstance()->getService(\Okay\Core\Config::class)->get('db_prefix');

        return $prefix . ltrim($table, '_');
    }
}
