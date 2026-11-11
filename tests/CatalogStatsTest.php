<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogStats;
use PHPUnit\Framework\TestCase;

/** «N з M піде в Google» і причини решти: сума причин і надісланих — завжди всі видимі варіанти. */
class CatalogStatsTest extends TestCase
{
    public function testReasonsPartitionTotal(): void
    {
        $rows = [
            $this->row(1, image: false),                       // без фото, хоч і виключений брендом
            $this->row(2, price: 0),
            $this->row(3, categories: [50]),                    // поза вибраними
            $this->row(4, categories: [11]),                    // виключено категорією (нащадок 10)
            $this->row(5, brand: 7),
            $this->row(6, product: 66),
            $this->row(7),
            $this->row(8, brand: null),
        ];
        $catalog = new CatalogSettings(mode: 'selected', includeCategories: [1], excludeBrands: [7], excludeProducts: [66]);

        $stats = CatalogStats::partition($rows, $catalog, [1, 10, 11], [10, 11], null);

        $this->assertSame(8, $stats['total']);
        $this->assertSame(['no_image' => 1, 'zero_price' => 1, 'not_selected' => 1, 'excluded_category' => 1, 'excluded_brand' => 1, 'excluded_product' => 1], $stats['reasons']);
        $this->assertSame(2, $stats['sent']);
    }

    /** Наявність після розширень і мінімальну ціну видно лише зі збірки — їх додає останній запуск. */
    public function testLastRunReasonsAreAddedSeparately(): void
    {
        $stats = CatalogStats::partition([$this->row(1), $this->row(2), $this->row(3)], new CatalogSettings(), [], [], ['out_of_stock' => 2, 'min_price' => 0]);

        $this->assertSame(['out_of_stock' => 2], $stats['reasons']);
        $this->assertSame(1, $stats['sent']);
    }

    public function testDiscontinuedFromLastRunIsShown(): void
    {
        $stats = CatalogStats::partition([$this->row(1), $this->row(2)], new CatalogSettings(), [], [], ['discontinued' => 1]);

        $this->assertSame(['discontinued' => 1], $stats['reasons']);
        $this->assertSame(1, $stats['sent']);
    }

    /** «Вибрані» без вибору — нічого не піде, і лічильник це показує. */
    public function testSelectedWithEmptyListsSendsNothing(): void
    {
        $stats = CatalogStats::partition([$this->row(1), $this->row(2)], new CatalogSettings(mode: 'selected'), [], [], null);

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(['not_selected' => 2], $stats['reasons']);
    }

    /** @param list<int> $categories */
    private function row(int $id, bool $image = true, float $price = 100.0, array $categories = [1], ?int $brand = 3, ?int $product = null): array
    {
        $productId = $product ?? $id;

        return ['product_id' => $productId, 'has_image' => $image, 'price' => $price, 'brand_id' => $brand, 'category_ids' => $categories];
    }
}
