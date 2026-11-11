<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Mapping\ItemMapper;
use Okay\Modules\Sviat\MerchantApi\Mapping\PriceBucketLabel;
use PHPUnit\Framework\TestCase;

class ItemMapperTest extends TestCase
{
    public function testMapsFullBackorderItem(): void
    {
        $item = [
            'title' => ['data' => 'Скло &quot;A&amp;B&quot;, 123'],
            'link' => ['data' => 'https://shop.example/ru/catalog/item-123'],
            'description' => ['data' => 'Опис &lt;b&gt;'],
            'g:id' => ['data' => '501'],
            'g:mpn' => ['data' => '123'],
            'g:condition' => ['data' => 'new'],
            'g:price' => ['data' => '2600 UAH'],
            'g:sale_price' => ['data' => '2499.5 UAH'],
            'g:availability' => ['data' => 'backorder'],
            'g:brand' => ['data' => 'Brand'],
            'g:adult' => ['data' => 'false'],
            'g:product_type' => ['data' => 'Категорія'],
            ['tag' => 'g:image_link', 'data' => 'https://shop.example/files/a.jpg'],
            ['tag' => 'g:additional_image_link', 'data' => 'https://shop.example/files/b.jpg'],
            ['tag' => 'g:additional_image_link', 'data' => 'https://shop.example/files/c.jpg'],
            'g:availability_date' => ['data' => '2026-11-09T12:00+0200'],
            'g:excluded_destination:Shopping_ads' => ['tag' => 'g:excluded_destination', 'data' => 'Shopping_ads'],
            'g:excluded_destination:Display_ads' => ['tag' => 'g:excluded_destination', 'data' => 'Display_ads'],
            'g:excluded_destination:Local_inventory_ads' => ['tag' => 'g:excluded_destination', 'data' => 'Local_inventory_ads'],
            'g:sale_price_effective_date' => ['data' => '2026-10-01T00:00:00Z/2026-11-01T00:00:00Z'],
        ];

        $mapped = new ItemMapper(new PriceBucketLabel(3, [100, 1000, 3000]))->fromFeedItem($item, 'ru', 'LANG_X');

        $this->assertSame([], $mapped->problems);
        $this->assertSame('501', $mapped->offerId);
        $this->assertSame([
            'offerId' => '501',
            'contentLanguage' => 'ru',
            'feedLabel' => 'LANG_X',
            'productAttributes' => [
                'title' => 'Скло "A&B", 123',
                'link' => 'https://shop.example/ru/catalog/item-123',
                'description' => 'Опис <b>',
                'mpn' => '123',
                'condition' => 'NEW',
                'price' => ['amountMicros' => '2600000000', 'currencyCode' => 'UAH'],
                'salePrice' => ['amountMicros' => '2499500000', 'currencyCode' => 'UAH'],
                'availability' => 'BACKORDER',
                'brand' => 'Brand',
                'adult' => false,
                'productTypes' => ['Категорія'],
                'imageLink' => 'https://shop.example/files/a.jpg',
                'additionalImageLinks' => ['https://shop.example/files/b.jpg', 'https://shop.example/files/c.jpg'],
                'availabilityDate' => '2026-11-09T12:00:00+02:00',
                'excludedDestinations' => ['SHOPPING_ADS', 'DISPLAY_ADS', 'LOCAL_INVENTORY_ADS'],
                'salePriceEffectiveDate' => ['startTime' => '2026-10-01T00:00:00+00:00', 'endTime' => '2026-11-01T00:00:00+00:00'],
                'customLabel3' => '1001-3000',
            ],
        ], $mapped->productInput);
    }

    public function testPriceLabelUsesRegularPriceWithoutSale(): void
    {
        $mapped = new ItemMapper(new PriceBucketLabel(0, [100, 1000]))->map(
            ['g:id' => ['1'], 'g:price' => ['999 UAH']],
            'uk',
            'UA'
        );

        $this->assertSame('101-1000', $mapped->productInput['productAttributes']['customLabel0']);
    }

    public function testUnknownAndBrokenValuesAreReportedNotDropped(): void
    {
        $mapped = new ItemMapper()->map([
            'g:id' => ['7'],
            'g:shipping' => ['UA:::0 UAH'],
            'g:price' => ['12,50 UAH'],
            'g:availability' => ['soon'],
            'g:availability_date' => ['16.11.2026'],
            'g:excluded_destination' => ['Shopping_ads', 'Everything'],
        ], 'uk', 'UA');

        $this->assertSame([
            'unknown_tag:g:shipping',
            'bad_value:g:price',
            'bad_value:g:availability',
            'bad_value:g:availability_date',
            'bad_value:g:excluded_destination',
        ], $mapped->problems);
        $this->assertSame(['SHOPPING_ADS'], $mapped->productInput['productAttributes']['excludedDestinations']);
        $this->assertArrayNotHasKey('price', $mapped->productInput['productAttributes']);
    }

    /** Характеристика без значення — помилка запису, а не мовчазний пропуск. */
    public function testIncompleteProductDetailIsReported(): void
    {
        $mapped = new ItemMapper()->fromFeedItem([
            'g:id' => ['data' => '1'],
            'g:product_detail' => ['data' => ['g:attribute_name' => ['data' => 'x']]],
        ], 'uk', 'UA');

        $this->assertSame(['bad_value:g:product_detail'], $mapped->problems);
        $this->assertArrayNotHasKey('productDetails', $mapped->productInput['productAttributes']);
    }

    /** І плоский формат MerchantApi, і вкладений формат пресета Feeds; HTML-екранування знімається. */
    public function testProductDetailMapsToProductDetails(): void
    {
        $mapped = new ItemMapper()->fromFeedItem([
            'g:id' => ['data' => '1'],
            ['tag' => 'g:product_detail', 'data' => ['g:attribute_name' => 'Напруга', 'g:attribute_value' => '220 &amp; 230 В']],
            ['tag' => 'g:product_detail', 'data' => ['g:section_name' => ['data' => 'Загальне'], 'g:attribute_name' => ['data' => 'Потужність'], 'g:attribute_value' => ['data' => '2 кВт']]],
            'g:nested' => ['data' => ['x' => 1]],
        ], 'uk', 'UA');

        $this->assertSame([
            ['attributeName' => 'Напруга', 'attributeValue' => '220 & 230 В'],
            ['sectionName' => 'Загальне', 'attributeName' => 'Потужність', 'attributeValue' => '2 кВт'],
        ], $mapped->productInput['productAttributes']['productDetails']);
        $this->assertSame(['unsupported:g:nested'], $mapped->problems);
    }

    public function testMissingIdIsAProblem(): void
    {
        $this->assertSame(['missing:g:id'], new ItemMapper()->map(['title' => ['x']], 'uk', 'UA')->problems);
    }

    public function testTitleAndDescriptionAreCutToGoogleLimits(): void
    {
        $mapped = new ItemMapper()->map(['g:id' => ['1'], 'title' => [str_repeat('я', 200)], 'description' => [str_repeat('д', 6000)]], 'uk', 'UA');

        $this->assertSame(150, mb_strlen($mapped->productInput['productAttributes']['title']));
        $this->assertSame(5000, mb_strlen($mapped->productInput['productAttributes']['description']));
    }

    public function testPriceParsingIsExactWithoutFloat(): void
    {
        $this->assertSame(['amountMicros' => '0', 'currencyCode' => 'UAH'], ItemMapper::price('0 UAH'));
        $this->assertSame(['amountMicros' => '100000', 'currencyCode' => 'UAH'], ItemMapper::price('0.1 UAH'));
        $this->assertSame(['amountMicros' => '999999999999999999', 'currencyCode' => 'UAH'], ItemMapper::price('999999999999.999999 UAH'));
        $this->assertNull(ItemMapper::price('1e3 UAH'));
        $this->assertNull(ItemMapper::price('-5 UAH'));
        $this->assertNull(ItemMapper::price('5 uah'));
    }

    public function testTimestamps(): void
    {
        $this->assertSame('2026-11-16T12:00:00+02:00', ItemMapper::timestamp('2026-11-16T12:00+0200'));
        $this->assertSame('2026-11-16T12:00:30+02:00', ItemMapper::timestamp('2026-11-16T12:00:30+02:00'));
        $this->assertSame('2026-11-16T12:00:00+00:00', ItemMapper::timestamp('2026-11-16T12:00:00Z'));
        $this->assertNull(ItemMapper::timestamp('2026-13-40T12:00+0200'));
        $this->assertNull(ItemMapper::timestamp('2026-11-16'));
    }
}
