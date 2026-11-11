<?php

namespace Modules\Sviat\MerchantApi;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Entities\CurrenciesEntity;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogItemBuilder;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WithoutDatabase.php';

/**
 * Запис товару — той самий, що давав GoogleMerchantHelper::getItem: інакше після переходу Google перепровіряв би
 * весь каталог, а модулі-розширення працюють саме з цим форматом.
 */
class CatalogItemTest extends TestCase
{
    use WithoutDatabase;

    public function testFieldsMatchGoogleMerchantShape(): void
    {
        $item = $this->builder()->getItem($this->product(), $this->categories(), false, new CatalogSettings());

        $this->assertSame([
            'title' => ['data' => 'Скло Bosch 2 мм'],
            'link' => ['data' => 'https://shop.example/catalog/glass'],
            'description' => ['data' => 'Опис &amp; ще'],
            'g:id' => ['data' => '501'],
            'g:mpn' => ['data' => 'SKU-1'],
            'g:condition' => ['data' => 'new'],
            'g:price' => ['data' => '2600 UAH'],
            'g:sale_price' => ['data' => '2500 UAH'],
            'g:availability' => ['data' => 'in stock'],
            'g:brand' => ['data' => 'Bosch'],
            'g:adult' => ['data' => 'false'],
            'g:product_type' => ['data' => 'Побутова техніка > Плити'],
            ['tag' => 'g:image_link', 'data' => 'resized/a.jpg'],
            ['tag' => 'g:additional_image_link', 'data' => 'resized/b.jpg'],
        ], $item);
    }

    public function testSecondVariantLinksToVariantAndZeroStockIsOutOfStock(): void
    {
        $item = $this->builder()->getItem($this->product(['stock' => 0, 'compare_price' => null]), $this->categories(), true, new CatalogSettings());

        $this->assertSame('https://shop.example/catalog/glass?variant=501', $item['link']['data']);
        $this->assertSame('out of stock', $item['g:availability']['data']);
        $this->assertSame('2500 UAH', $item['g:price']['data']);
        $this->assertArrayNotHasKey('g:sale_price', $item);
    }

    /** Колір і розмір для запчастин не потрібні; adult завжди false, як GoogleMerchant завжди писав. */
    public function testNoColorOrSizeAndAdultIsAlwaysFalse(): void
    {
        $item = $this->builder()->getItem($this->product(), $this->categories(), false, new CatalogSettings());

        $this->assertArrayNotHasKey('g:color', $item);
        $this->assertArrayNotHasKey('g:size', $item);
        $this->assertSame('false', $item['g:adult']['data']);
    }

    /** Лише вибрані властивості, назва й значення в межах Google, не більше 100 на товар. */
    public function testProductDetailOnlyForChosenFeaturesAndLimits(): void
    {
        $features = [
            7 => ['id' => '7', 'name' => 'Напруга', 'values' => ['220 В'], 'values_string' => '220 В'],
            8 => ['id' => '8', 'name' => str_repeat('н', 200), 'values' => [str_repeat('з', 1200)], 'values_string' => str_repeat('з', 1200)],
            9 => ['id' => '9', 'name' => 'Склад', 'values' => ['A'], 'values_string' => 'A'],
        ];
        $item = $this->builder()->getItem($this->product(['features' => $features]), $this->categories(), false, new CatalogSettings(detailFeatureIds: [7, 8]));

        $details = array_values(array_filter($item, static fn($e) => is_array($e) && ($e['tag'] ?? null) === 'g:product_detail'));
        $this->assertCount(2, $details);
        $this->assertSame(['g:attribute_name' => 'Напруга', 'g:attribute_value' => '220 В'], $details[0]['data']);
        $this->assertSame(140, mb_strlen($details[1]['data']['g:attribute_name']));
        $this->assertSame(1000, mb_strlen($details[1]['data']['g:attribute_value']));

        $many = [];
        foreach (range(1, 120) as $id) {
            $many[$id] = ['id' => (string) $id, 'name' => "F{$id}", 'values' => ['v'], 'values_string' => 'v'];
        }
        $item = $this->builder()->getItem($this->product(['features' => $many]), $this->categories(), false, new CatalogSettings(detailFeatureIds: range(1, 120)));
        $this->assertCount(100, array_filter($item, static fn($e) => is_array($e) && ($e['tag'] ?? null) === 'g:product_detail'));
    }

    /** «Повний»: повний опис, а якщо порожній — анотація; «короткий» лишається як у GoogleMerchant. */
    public function testFullDescriptionFallsBackToAnnotation(): void
    {
        $full = new CatalogSettings(description: 'full');
        $short = new CatalogSettings();

        $this->assertSame('Анотація', CatalogItemBuilder::withFallbackDescription((object) ['description' => '', 'fallback_description' => 'Анотація'], $full)->description);
        $this->assertSame('Повний', CatalogItemBuilder::withFallbackDescription((object) ['description' => 'Повний', 'fallback_description' => 'Анотація'], $full)->description);
        $this->assertSame('', CatalogItemBuilder::withFallbackDescription((object) ['description' => '', 'fallback_description' => 'Повний'], $short)->description);
    }

    /**
     * Запит товарів небуферизований: будь-який SQL у циклі обриває курсор, і збірка закінчується на першому товарі
     * (так і було на звірці). Тож усе з бази — у getQuery, до циклу.
     */
    public function testGetItemRunsNoQueriesAfterGetQuery(): void
    {
        $calls = 0;
        $builder = $this->builder(onEntity: static function () use (&$calls) {
            $calls++;
        });
        $builder->getQuery(new CatalogSettings());
        $before = $calls;

        $builder->getItem($this->product(), $this->categories(), false, new CatalogSettings());
        $builder->getItem($this->product(['variant_id' => 502]), $this->categories(), true, new CatalogSettings());

        $this->assertSame($before, $calls);
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): object
    {
        return (object) ($overrides + [
            'product_name' => 'Скло Bosch',
            'variant_name' => '2 мм',
            'url' => 'glass',
            'slug_url' => null,
            'description' => 'Опис & ще',
            'variant_id' => 501,
            'sku' => 'SKU-1',
            'price' => 2500.0,
            'compare_price' => 2600.0,
            'currency_id' => 1,
            'stock' => 3,
            'brand_name' => 'Bosch',
            'main_category_id' => 4,
            'images' => ['a.jpg', 'b.jpg'],
            'features' => [],
        ]);
    }

    /** @return array<int, object> */
    private function categories(): array
    {
        return [4 => (object) ['path' => [(object) ['name' => 'Побутова техніка'], (object) ['name' => 'Плити']]]];
    }

    private function builder(?\Closure $onEntity = null): CatalogItemBuilder
    {
        $currency = (object) ['id' => 1, 'code' => 'UAH', 'rate_from' => 1, 'rate_to' => 1];
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn($currency);
        $currencies->method('find')->willReturn([1 => $currency]);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static function () use ($currencies, $onEntity) {
            $onEntity?->__invoke();

            return $currencies;
        });

        $feedHelper = $this->createStub(XmlFeedHelper::class);
        $feedHelper->method('joinImages')->willReturnArgument(0);
        $feedHelper->method('joinFeatures')->willReturnArgument(0);
        $feedHelper->method('escape')->willReturnCallback(static fn($v) => htmlspecialchars(strip_tags((string) $v)));
        $image = $this->createStub(Image::class);
        $image->method('getResizeModifier')->willReturnCallback(static fn($file) => "resized/{$file}");
        $money = $this->createStub(Money::class);
        $money->method('convert')->willReturnCallback(static fn($price) => (string) $price);

        $languages = $this->createStub(Languages::class);
        $languages->method('getLangId')->willReturn(1);

        return new class (
            $languages,
            new QueryFactory(new AuraQueryFactory('mysql')),
            $feedHelper,
            $entityFactory,
            $image,
            $money,
        ) extends CatalogItemBuilder {
            protected function productUrl(object $product, bool $addVariantUrl): string
            {
                return 'https://shop.example/catalog/' . $product->url . ($addVariantUrl ? '?variant=' . $product->variant_id : '');
            }
        };
    }
}
