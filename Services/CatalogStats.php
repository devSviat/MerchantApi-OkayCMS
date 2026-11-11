<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\QueryFactory;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\VariantsEntity;

/**
 * Скільки товарів піде в Google і чому решта — ні. Кожен видимий варіант рахується за першою причиною
 * в порядку REASONS, тож сума причин і надісланих дорівнює всім видимим варіантам.
 */
class CatalogStats
{
    public const array REASONS = ['no_image', 'zero_price', 'not_selected', 'excluded_category', 'excluded_brand', 'excluded_product'];
    // Їх видно лише зі зібраних записів (наявність ставлять розширення, ціну — акції, «знято з виробництва» —
    // Sviat/ProductDiscontinued), тож вони з останнього запуску.
    public const array RUN_REASONS = ['discontinued', 'out_of_stock', 'min_price'];
    private const int SAMPLES = 20;

    public function __construct(
        private QueryFactory $queryFactory,
        private EntityFactory $entityFactory,
        private Languages $languages,
    ) {
    }

    /**
     * @param array<string, int>|null $lastRunSkipped summary.skipped останнього запуску синхронізації
     * @return array{total: int, sent: int, reasons: array<string, int>, samples: array<string, list<array{product_id: int, name: string}>>}
     */
    public function summarize(CatalogSettings $catalog, ?array $lastRunSkipped): array
    {
        $categories = $this->entityFactory->get(CategoriesEntity::class);

        return self::partition(
            $this->rows(),
            $catalog,
            CatalogItemBuilder::expand($categories, $catalog->includeCategories),
            CatalogItemBuilder::expand($categories, $catalog->excludeCategories),
            $lastRunSkipped,
        );
    }

    /**
     * @param iterable<array{product_id: int, name: string, has_image: bool, price: float, brand_id: ?int, category_ids: list<int>}> $rows
     * @param list<int> $includeCategories уже з нащадками
     * @param list<int> $excludeCategories уже з нащадками
     * @param array<string, int>|null $lastRunSkipped
     * @return array{total: int, sent: int, reasons: array<string, int>, samples: array<string, list<array{product_id: int, name: string}>>}
     */
    public static function partition(iterable $rows, CatalogSettings $catalog, array $includeCategories, array $excludeCategories, ?array $lastRunSkipped): array
    {
        $include = array_flip($includeCategories);
        $exclude = array_flip($excludeCategories);
        $includeBrands = array_flip($catalog->includeBrands);
        $includeProducts = array_flip($catalog->includeProducts);
        $excludeBrands = array_flip($catalog->excludeBrands);
        $excludeProducts = array_flip($catalog->excludeProducts);

        $total = 0;
        $reasons = [];
        $samples = [];
        foreach ($rows as $row) {
            $total++;
            $inAny = static fn(array $set): bool => array_intersect_key(array_flip($row['category_ids']), $set) !== [];
            $reason = match (true) {
                !$row['has_image'] => 'no_image',
                $row['price'] <= 0 => 'zero_price',
                $catalog->mode === CatalogSettings::MODE_SELECTED
                    && !isset($includeProducts[$row['product_id']])
                    && !($row['brand_id'] !== null && isset($includeBrands[$row['brand_id']]))
                    && !$inAny($include) => 'not_selected',
                $inAny($exclude) => 'excluded_category',
                $row['brand_id'] !== null && isset($excludeBrands[$row['brand_id']]) => 'excluded_brand',
                isset($excludeProducts[$row['product_id']]) => 'excluded_product',
                default => null,
            };
            if ($reason === null) {
                continue;
            }
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            if (count($samples[$reason] ?? []) < self::SAMPLES && !isset($samples[$reason][$row['product_id']])) {
                $samples[$reason][$row['product_id']] = ['product_id' => $row['product_id'], 'name' => $row['name']];
            }
        }

        foreach (self::RUN_REASONS as $reason) {
            if (($lastRunSkipped[$reason] ?? 0) > 0) {
                $reasons[$reason] = (int) $lastRunSkipped[$reason];
            }
        }

        return [
            'total' => $total,
            'sent' => max(0, $total - array_sum($reasons)),
            'reasons' => $reasons,
            'samples' => array_map(array_values(...), $samples),
        ];
    }

    /** @return list<array{product_id: int, name: string, has_image: bool, price: float, brand_id: ?int, category_ids: list<int>}> */
    private function rows(): array
    {
        $rows = $this->queryFactory->newSelect()
            ->cols([
                'p.id AS product_id',
                'lp.name AS name',
                "(p.main_image_id != '' AND p.main_image_id IS NOT NULL) AS has_image",
                'v.price',
                'p.brand_id',
                'GROUP_CONCAT(DISTINCT pc.category_id) AS category_ids',
            ])
            ->from(VariantsEntity::getTable() . ' AS v')
            ->join('INNER', ProductsEntity::getTable() . ' AS p', 'p.id = v.product_id')
            ->leftJoin(ProductsEntity::getLangTable() . ' AS lp', 'lp.product_id = p.id AND lp.lang_id = ' . (int) $this->languages->getLangId())
            ->leftJoin('__products_categories AS pc', 'pc.product_id = p.id')
            ->where('p.visible')
            ->groupBy(['v.id'])
            ->results();

        return array_map(static fn(object $row) => [
            'product_id' => (int) $row->product_id,
            'name' => (string) $row->name,
            'has_image' => (bool) $row->has_image,
            'price' => (float) $row->price,
            'brand_id' => $row->brand_id !== null ? (int) $row->brand_id : null,
            'category_ids' => array_map(intval(...), array_filter(explode(',', (string) $row->category_ids), static fn($id) => $id !== '')),
        ], $rows);
    }
}
