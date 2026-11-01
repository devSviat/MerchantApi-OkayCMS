<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Aura\Sql\ExtendedPdo;
use InvalidArgumentException;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\Request;
use Okay\Core\Router;
use Okay\Core\Routes\ProductRoute;
use Okay\Core\Settings;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\OkayCMS\GoogleMerchant\Entities\GoogleMerchantRelationsEntity;
use Okay\Modules\OkayCMS\GoogleMerchant\Helpers\GoogleMerchantHelper;
use Okay\Modules\Sviat\MerchantApi\Sync\ItemSource;

/**
 * Записи фіда Google тим самим конвеєром, що й GoogleMerchantController::render,
 * тож розширення інших модулів (BackorderLeadTime, ProductDiscontinued, Promo) діють так само.
 *
 * Лише для процесу планувальника: ProductRoute::setNotUseSqlToGenerate() назад не вмикається,
 * і після збірки генерація URL у цьому процесі вже не ходить у базу.
 */
class FeedItemBuilder implements ItemSource
{
    private static ?int $processLangId = null;

    // @phpstan-ignore constructor.unusedParameter ($router — див. коментар біля параметра)
    public function __construct(
        private ExtendedPdo $pdo,
        private Database $db,
        private QueryFactory $queryFactory,
        private EntityFactory $entityFactory,
        private Languages $languages,
        private Settings $settings,
        private Image $image,
        private Money $money,
        // Лише створення: статичні Router::generateUrl/generateRouterCache працюють після того, як
        // контейнер створив Router, а в процесі планувальника цього більше ніхто не робить.
        Router $router,
    ) {
    }

    /**
     * @param string $siteUrl корінь сайту (https://host[/subdir]): у CLI немає HTTP-запиту, з якого його взяти
     * @return array<string, array<int|string, mixed>> ID варіанта → запис фіда
     */
    public function build(int $langId, int $feedId, string $siteUrl): array
    {
        self::claimLanguage($langId);
        self::applySiteUrl($siteUrl);

        $originalLangId = $this->languages->getLangId();
        $this->languages->setLangId($langId);
        // Мовні налаштування (назва сайту, SEO-шаблони) читаються під поточну мову.
        $this->settings->initSettings();

        try {
            return $this->collect($feedId);
        } finally {
            $this->pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
            $this->languages->setLangId($originalLangId);
            $this->settings->initSettings();
        }
    }

    /** @return array<string, array<int|string, mixed>> */
    private function collect(int $feedId): array
    {
        // Свіжі екземпляри на кожну мову: XmlFeedHelper кешує категорії, шаблони й назву сайту в конструкторі.
        $feedHelper = new XmlFeedHelper($this->languages, $this->settings, $this->entityFactory);
        $helper = new GoogleMerchantHelper(
            $this->settings,
            $this->languages,
            $this->queryFactory,
            $feedHelper,
            $this->entityFactory,
            $this->image,
            $this->money,
        );

        foreach ($this->entityFactory->get(CurrenciesEntity::class)->find() as $currency) {
            $this->money->setCurrency($currency);
        }

        $this->db->query($this->queryFactory->newSqlQuery()->setStatement('SET SQL_BIG_SELECTS=1'));
        $this->queryFactory->newSqlQuery()->setStatement('SET SESSION group_concat_max_len = 1000000;')->execute();

        $categoryIds = $this->queryFactory->newSelect()
            ->from(GoogleMerchantRelationsEntity::getTable())
            ->cols(['entity_id'])
            ->where("feed_id = :feed_id AND entity_type = 'category'")
            ->bindValue('feed_id', $feedId)
            ->results('entity_id');
        $uploadCategories = $feedHelper->addAllChildrenToList($categoryIds);

        // У вебі маршрути вже готові до контролера; тут вони ініціалізувались би ліниво на першому
        // generateUrl усередині циклу, а їхні SQL обірвали б небуферизований курсор після першого товару.
        // routes.php читає REQUEST_URI, якого в CLI немає.
        $_SERVER['REQUEST_URI'] ??= '/';
        Router::getRouteByName('product');
        Router::generateRouterCache();
        $allCategories = $this->entityFactory->get(CategoriesEntity::class)->mappedBy('id')->find();
        ProductRoute::setNotUseSqlToGenerate();

        // Як у контролері: небуферизований запит, тож усередині циклу жодного SQL (розширення модулів
        // вантажать своє в хуку getQuery, до циклу).
        $this->pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $query = $helper->getQuery($feedId, $uploadCategories);

        $items = [];
        $previousProductId = null;
        while ($product = $query->result()) {
            $product = $feedHelper->attachFeatures($product);
            $metaParts = $feedHelper->getMetadataParts($product);
            $product = $feedHelper->attachDescriptionByTemplate($product, $metaParts, $feedHelper->getDescriptionTemplate($product), XmlFeedHelper::DESCRIPTION_FIELD);
            $product = $feedHelper->attachDescriptionByTemplate($product, $metaParts, $feedHelper->getAnnotationTemplate($product), XmlFeedHelper::ANNOTATION_FIELD);
            $product = $feedHelper->attachProductImages($product);

            // Як у фіді: посилання на конкретний варіант — з другого рядка того самого товару.
            $addVariantUrl = $previousProductId === $product->product_id;
            $previousProductId = $product->product_id;

            $items[(string) $product->variant_id] = $helper->getItem($product, $allCategories, $addVariantUrl);
        }

        return $items;
    }

    /**
     * Одна мова на процес. Сутності — одиночки процесу, і CategoriesEntity кешує дерево категорій
     * мовою першої збірки: друга мова отримала б чужі назви категорій і автоописи (так і було
     * на звірці 26.10.2026). Кожна мова — окрема задача планувальника, тобто окремий процес.
     */
    public static function claimLanguage(int $langId): void
    {
        if (self::$processLangId !== null && self::$processLangId !== $langId) {
            throw new \LogicException('FeedItemBuilder: one language per process');
        }
        self::$processLangId = $langId;
    }

    /** Домен, протокол і підтека для абсолютних посилань на товари й зображення. */
    public static function applySiteUrl(string $siteUrl): void
    {
        $parts = parse_url($siteUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Site URL must be http(s)://host[:port][/subdir]');
        }

        Request::setProtocol($parts['scheme']);
        Request::setDomain($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        // setSubDir('') записав би '/', і посилання отримали б подвійний слеш; без підтеки Request
        // рахує порожню сам (у CLI `ok` робить chdir у корінь застосунку).
        $subDir = trim($parts['path'] ?? '', '/');
        if ($subDir !== '') {
            Request::setSubDir($subDir);
        }
    }
}
