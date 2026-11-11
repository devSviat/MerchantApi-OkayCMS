<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\Router;
use Okay\Core\Routes\ProductRoute;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\RouterCacheEntity;
use Okay\Entities\VariantsEntity;
use Okay\Helpers\XmlFeedHelper;

/**
 * Вибірка товарів для Google і запис одного товару. Формат запису й точки розширення ті самі, що в
 * GoogleMerchantHelper (getQuery/getItem), тож модулі, які доповнюють фід (наявність «під замовлення», акції,
 * зняті з виробництва), підключаються сюди тими самими методами.
 *
 * Створюється на кожну мову: XmlFeedHelper кешує категорії, шаблони й назву сайту мовою, з якою його створили.
 */
class CatalogItemBuilder
{
    /**
     * Ключ запису, яким розширення getItem знімає товар зі збірки. Значення — причина з CatalogStats::RUN_REASONS,
     * її показує лічильник вкладки «Товари»; невідому причину збірка ігнорує.
     */
    public const string SKIP = 'sviat:skip';

    // Як GoogleMerchant: головне фото й до 10 додаткових.
    private const int MAX_IMAGES = 11;
    // Обмеження Google на характеристики: https://support.google.com/merchants/answer/9218260
    private const int MAX_DETAILS = 100;
    private const int DETAIL_NAME_MAX = 140;
    private const int DETAIL_VALUE_MAX = 1000;

    private ?object $mainCurrency = null;
    /** @var array<int, object>|null */
    private ?array $currencies = null;

    public function __construct(
        private Languages $languages,
        private QueryFactory $queryFactory,
        private XmlFeedHelper $feedHelper,
        private EntityFactory $entityFactory,
        private Image $image,
        private Money $money,
    ) {
    }

    /**
     * Мовні поля приєднуються після фільтрації — так GoogleMerchantHelper робить заради швидкодії.
     * Розширення отримують Select до виконання: тут вони вантажать своє, бо в циклі курсор небуферизований.
     */
    public function getQuery(CatalogSettings $catalog): Select
    {
        // Курсор вибірки небуферизований: SQL у циклі getItem обірвав би його після першого товару.
        $this->mainCurrency();
        $this->currencies();

        $langId = (int) $this->languages->getLangId();
        [$descriptionField, $fallbackField] = $catalog->description === CatalogSettings::DESCRIPTION_FULL
            ? ['lp.description', 'lp.annotation']
            : ['lp.annotation', 'lp.description'];

        $sql = $this->queryFactory->newSelect();
        $sql->cols([
            't.*',
            'lp.name as product_name',
            'lv.name as variant_name',
            'lb.name as brand_name',
            $descriptionField . ' AS description',
            $fallbackField . ' AS fallback_description',
        ])
            ->fromSubSelect($this->getSubSelect($catalog), 't')
            ->leftJoin(ProductsEntity::getLangTable() . ' AS lp', 'lp.product_id = t.product_id and lp.lang_id=' . $langId)
            ->leftJoin(VariantsEntity::getLangTable() . ' AS lv', 'lv.variant_id = t.variant_id and lv.lang_id=' . $langId)
            ->leftJoin(BrandsEntity::getLangTable() . ' AS lb', 'lb.brand_id = t.brand_id and lb.lang_id=' . $langId);

        return ExtenderFacade::execute(__METHOD__, $sql, func_get_args());
    }

    /**
     * Запис товару. Поля й обчислення — рядок у рядок як GoogleMerchantHelper::getItem, крім кольору й розміру
     * (для запчастин не потрібні) і характеристик: зміна будь-якого поля змусила б Google перепровірити товар.
     *
     * @param object $product рядок getQuery після attachFeatures, attachDescriptionByTemplate і attachProductImages
     * @param array<int, object> $allCategories для шляху категорії (product_type)
     * @param bool $addVariantUrl посилання на конкретний варіант — для другого й далі рядків товару
     * @return array<int|string, mixed>
     */
    public function getItem(object $product, array $allCategories, bool $addVariantUrl, CatalogSettings $catalog): array
    {
        $title = $product->product_name . (!empty($product->variant_name) ? ' ' . $product->variant_name : '');
        $result['title']['data'] = $this->feedHelper->escape($title);
        $result['link']['data'] = $this->productUrl($product, $addVariantUrl);
        $result['description']['data'] = $this->feedHelper->escape($product->description);
        $result['g:id']['data'] = $this->feedHelper->escape($product->variant_id);
        if (!empty($product->sku)) {
            $result['g:mpn']['data'] = $this->feedHelper->escape($product->sku);
        }
        $result['g:condition']['data'] = 'new';

        $mainCurrency = $this->mainCurrency();
        $price = round($product->price, 2);
        $comparePrice = round($product->compare_price ?? 0, 2);
        $variantCurrency = $this->currencies()[$product->currency_id] ?? null;
        if ($variantCurrency !== null && !empty($product->currency_id) && $variantCurrency->rate_from != $variantCurrency->rate_to) {
            $price = round($product->price * $variantCurrency->rate_to / $variantCurrency->rate_from, 2);
            if (!empty($product->compare_price)) {
                $comparePrice = round($product->compare_price * $variantCurrency->rate_to / $variantCurrency->rate_from, 2);
            }
        }
        $price = $this->money->convert($price, $mainCurrency->id, false);
        $comparePrice = $this->money->convert($comparePrice, $mainCurrency->id, false);

        if ($product->compare_price > $product->price) {
            $result['g:price']['data'] = $this->feedHelper->escape($comparePrice . ' ' . $mainCurrency->code);
            $result['g:sale_price']['data'] = $this->feedHelper->escape($price . ' ' . $mainCurrency->code);
        } else {
            $result['g:price']['data'] = $this->feedHelper->escape($price . ' ' . $mainCurrency->code);
        }

        $result['g:availability']['data'] = !in_array($product->stock, [0, '0'], true) ? 'in stock' : 'out of stock';
        if (!empty($product->brand_name)) {
            $result['g:brand']['data'] = $this->feedHelper->escape($product->brand_name);
        }
        // GoogleMerchant писав це поле завжди; без нього змінився б повний хеш кожного товару.
        $result['g:adult']['data'] = 'false';

        if (!empty($product->main_category_id) && !empty($allCategories[$product->main_category_id])) {
            $names = array_map(static fn(object $category) => $category->name, (array) $allCategories[$product->main_category_id]->path);
            $result['g:product_type']['data'] = implode(' > ', $names);
        }

        foreach (array_slice(array_values((array) ($product->images ?? [])), 0, self::MAX_IMAGES) as $index => $filename) {
            $result[] = [
                'tag' => $index === 0 ? 'g:image_link' : 'g:additional_image_link',
                'data' => $this->image->getResizeModifier($filename, 800, 600),
            ];
        }

        foreach ($this->productDetails($product, $catalog) as $detail) {
            $result[] = ['tag' => 'g:product_detail', 'data' => $detail];
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }

    /**
     * «Повний» опис: якщо порожній — анотація, а далі SEO-шаблон, який FeedItemBuilder підставляє в порожнє поле.
     * «Короткий» лишається як у GoogleMerchant: анотація, інакше шаблон.
     */
    public static function withFallbackDescription(object $product, CatalogSettings $catalog): object
    {
        if ($catalog->description === CatalogSettings::DESCRIPTION_FULL && empty($product->description) && !empty($product->fallback_description)) {
            $product->description = $product->fallback_description;
        }

        return $product;
    }

    protected function productUrl(object $product, bool $addVariantUrl): string
    {
        ProductRoute::setUrlSlugAlias($product->url, $product->slug_url);
        $params = ['url' => $product->url] + ($addVariantUrl ? ['variantId' => $product->variant_id] : []);

        return Router::generateUrl('product', $params, true);
    }

    /** @return list<array{'g:attribute_name': string, 'g:attribute_value': string}> */
    private function productDetails(object $product, CatalogSettings $catalog): array
    {
        $details = [];
        foreach ($catalog->detailFeatureIds as $featureId) {
            $feature = $product->features[$featureId] ?? null;
            if (!is_array($feature) || trim((string) ($feature['values_string'] ?? '')) === '') {
                continue;
            }
            $details[] = [
                'g:attribute_name' => mb_substr((string) $feature['name'], 0, self::DETAIL_NAME_MAX),
                'g:attribute_value' => mb_substr((string) $feature['values_string'], 0, self::DETAIL_VALUE_MAX),
            ];
            if (count($details) === self::MAX_DETAILS) {
                break;
            }
        }

        return $details;
    }

    private function mainCurrency(): object
    {
        return $this->mainCurrency ??= $this->entityFactory->get(CurrenciesEntity::class)->getMainCurrency();
    }

    /** @return array<int, object> */
    private function currencies(): array
    {
        if ($this->currencies === null) {
            $this->currencies = [];
            foreach ($this->entityFactory->get(CurrenciesEntity::class)->find() as $currency) {
                $this->currencies[(int) $currency->id] = $currency;
            }
        }

        return $this->currencies;
    }

    /**
     * Категорії разом з усіма нащадками.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function expandCategories(array $ids): array
    {
        return $ids === [] ? [] : self::expand($this->entityFactory->get(CategoriesEntity::class), $ids);
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    public static function expand(CategoriesEntity $categoriesEntity, array $ids): array
    {
        $expanded = [];
        foreach ($ids as $id) {
            $category = $categoriesEntity->get((int) $id);
            foreach (!empty($category) ? (array) $category->children : [] as $childId) {
                $expanded[(int) $childId] = (int) $childId;
            }
        }

        return array_values($expanded);
    }

    private function getSubSelect(CatalogSettings $catalog): Select
    {
        $sql = $this->queryFactory->newSelect();
        $sql->cols([
            'v.stock',
            'v.price',
            'v.compare_price',
            'v.sku',
            'v.weight',
            'v.currency_id',
            'v.id AS variant_id',
            'p.id AS product_id',
            'p.url',
            'r.slug_url',
            'p.main_category_id',
            'p.brand_id',
        ])
            ->from(VariantsEntity::getTable() . ' AS v')
            ->leftJoin(ProductsEntity::getTable() . ' AS p', 'v.product_id=p.id')
            ->leftJoin(RouterCacheEntity::getTable() . ' AS r', 'r.url = p.url AND r.type="product"')
            // Те, що Google однаково відхилить, не надсилається ніколи.
            ->where('p.visible')
            ->where("p.main_image_id != '' AND p.main_image_id IS NOT NULL")
            ->where('v.price > 0')
            ->groupBy(['v.id'])
            ->orderBy(['p.position DESC']);

        if ($catalog->mode === CatalogSettings::MODE_SELECTED) {
            $this->whereIncluded($sql, $catalog);
        }
        $this->whereNotExcluded($sql, $catalog);

        $sql = $this->feedHelper->joinImages($sql);

        return $this->feedHelper->joinFeatures($sql);
    }

    private function whereIncluded(Select $sql, CatalogSettings $catalog): void
    {
        $conditions = [];
        if ($catalog->includeProducts !== []) {
            $conditions[] = 'p.id IN (:catalog_include_products)';
            $sql->bindValue('catalog_include_products', $catalog->includeProducts);
        }
        if ($catalog->includeBrands !== []) {
            $conditions[] = 'p.brand_id IN (:catalog_include_brands)';
            $sql->bindValue('catalog_include_brands', $catalog->includeBrands);
        }
        $categories = $this->expandCategories($catalog->includeCategories);
        if ($categories !== []) {
            $conditions[] = 'p.id IN (SELECT product_id FROM __products_categories WHERE category_id IN (:catalog_include_categories))';
            $sql->bindValue('catalog_include_categories', $categories);
        }

        // «Вибрані» без вибору — нічого, а не все.
        $sql->where($conditions === [] ? '0 = 1' : '(' . implode(' OR ', $conditions) . ')');
    }

    private function whereNotExcluded(Select $sql, CatalogSettings $catalog): void
    {
        if ($catalog->excludeProducts !== []) {
            $sql->where('p.id NOT IN (:catalog_exclude_products)')->bindValue('catalog_exclude_products', $catalog->excludeProducts);
        }
        if ($catalog->excludeBrands !== []) {
            // NOT IN з NULL дає «невідомо», і товар без бренду випав би разом із виключеним брендом.
            $sql->where('(p.brand_id IS NULL OR p.brand_id NOT IN (:catalog_exclude_brands))')->bindValue('catalog_exclude_brands', $catalog->excludeBrands);
        }
        $categories = $this->expandCategories($catalog->excludeCategories);
        if ($categories !== []) {
            $sql->where('p.id NOT IN (SELECT product_id FROM __products_categories WHERE category_id IN (:catalog_exclude_categories))')
                ->bindValue('catalog_exclude_categories', $categories);
        }
    }
}
