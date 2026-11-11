<?php

namespace Modules\Sviat\MerchantApi;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogItemBuilder;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WithoutDatabase.php';

/**
 * Запит вибору товарів: що завжди відсіюється, «усі / вибрані» й винятки. Категорія товару — будь-яка з
 * його категорій, разом із нащадками вибраної.
 */
class CatalogQueryTest extends TestCase
{
    use WithoutDatabase;

    /** Дерево: 1 → 2 → 3 → 4; 10 окремо. */
    private const array CHILDREN = [1 => [1, 2, 3, 4], 2 => [2, 3, 4], 3 => [3, 4], 4 => [4], 10 => [10]];

    public function testAllModeHasNoInclusionClause(): void
    {
        $sql = $this->sql(new CatalogSettings());

        $this->assertStringContainsString('p.visible', $sql);
        $this->assertStringContainsString('v.price > 0', $sql);
        $this->assertStringContainsString('p.main_image_id', $sql);
        $this->assertStringNotContainsString(':catalog_include', $sql);
    }

    public function testSelectedModeIncludesProductsBrandsAndCategoryChildren(): void
    {
        [$sql, $binds] = $this->query(new CatalogSettings(mode: 'selected', includeCategories: [2], includeBrands: [7], includeProducts: [99]));

        $this->assertStringContainsString(':catalog_include_products', $sql);
        $this->assertStringContainsString(':catalog_include_brands', $sql);
        $this->assertStringContainsString('__products_categories', $sql);
        $this->assertSame([2, 3, 4], $binds['catalog_include_categories']);
        $this->assertSame([7], $binds['catalog_include_brands']);
        $this->assertSame([99], $binds['catalog_include_products']);
    }

    /** «Вибрані» без жодного вибору — нічого, а не все. */
    public function testSelectedModeWithEmptyListsSelectsNothing(): void
    {
        $this->assertStringContainsString('0 = 1', $this->sql(new CatalogSettings(mode: 'selected')));
    }

    /** Виняток предка трьох рівнів прибирає онуків, і за будь-якою категорією товару, не лише головною. */
    public function testExclusionsUseAnyProductCategoryWithChildren(): void
    {
        [$sql, $binds] = $this->query(new CatalogSettings(excludeCategories: [1], excludeBrands: [5], excludeProducts: [8]));

        $this->assertSame([1, 2, 3, 4], $binds['catalog_exclude_categories']);
        $this->assertMatchesRegularExpression('/p\.id NOT IN \(SELECT product_id FROM __products_categories WHERE category_id IN \(:catalog_exclude_categories\)\)/', $sql);
        $this->assertStringContainsString('p.brand_id NOT IN (:catalog_exclude_brands)', $sql);
        $this->assertStringContainsString('p.id NOT IN (:catalog_exclude_products)', $sql);
        $this->assertStringNotContainsString('main_category_id IN', $sql);
    }

    /** Товар без бренду не має випадати через виняток бренду: NOT IN з NULL дає «невідомо». */
    public function testBrandExclusionKeepsProductsWithoutBrand(): void
    {
        $this->assertStringContainsString('(p.brand_id IS NULL OR p.brand_id NOT IN (:catalog_exclude_brands))', $this->sql(new CatalogSettings(excludeBrands: [5])));
    }

    public function testShortDescriptionSelectsAnnotationWithFullAsFallback(): void
    {
        $short = $this->sql(new CatalogSettings());
        $full = $this->sql(new CatalogSettings(description: 'full'));

        $this->assertStringContainsString('lp.annotation AS description', $short);
        $this->assertStringContainsString('lp.description AS fallback_description', $short);
        $this->assertStringContainsString('lp.description AS description', $full);
        $this->assertStringContainsString('lp.annotation AS fallback_description', $full);
    }

    private function sql(CatalogSettings $catalog): string
    {
        return $this->query($catalog)[0];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function query(CatalogSettings $catalog): array
    {
        $select = $this->builder()->getQuery($catalog);
        $binds = $select->getBindValues();

        return [str_replace('`', '', preg_replace('/\s+/', ' ', (string) $select->getStatement())), $binds];
    }

    private function builder(): CatalogItemBuilder
    {
        $categories = $this->createStub(CategoriesEntity::class);
        $categories->method('get')->willReturnCallback(
            static fn($id) => isset(self::CHILDREN[(int) $id]) ? (object) ['id' => (int) $id, 'children' => self::CHILDREN[(int) $id]] : false
        );
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object) ['id' => 1, 'code' => 'UAH']);
        $currencies->method('find')->willReturn([]);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static fn($class) => $class === CurrenciesEntity::class ? $currencies : $categories);

        $languages = $this->createStub(Languages::class);
        $languages->method('getLangId')->willReturn(1);

        $feedHelper = $this->createStub(XmlFeedHelper::class);
        $feedHelper->method('joinImages')->willReturnArgument(0);
        $feedHelper->method('joinFeatures')->willReturnArgument(0);

        return new CatalogItemBuilder(
            $languages,
            new QueryFactory(new AuraQueryFactory('mysql')),
            $feedHelper,
            $entityFactory,
            $this->createStub(Image::class),
            $this->createStub(Money::class),
        );
    }
}
