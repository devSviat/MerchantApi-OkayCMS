<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use PHPUnit\Framework\TestCase;

/** Один набір товарів на всі мови: що надсилати, що виключити і як збирати дані. */
class CatalogSettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function testDefaultsAreAllProductsShortDescriptionSendOutOfStock(): void
    {
        $catalog = CatalogSettings::fromSettings($this->settings());

        $this->assertSame('all', $catalog->mode);
        $this->assertSame([], $catalog->includeCategories);
        $this->assertSame([], $catalog->excludeProducts);
        $this->assertTrue($catalog->sendOutOfStock);
        $this->assertSame('short', $catalog->description);
        $this->assertNull($catalog->minPrice);
        $this->assertSame([], $catalog->detailFeatureIds);
    }

    /** Зіпсоване поле не тягне за собою решту: воно бере типове значення, інші лишаються. */
    public function testCorruptedValuesFallBackToDefaults(): void
    {
        $this->values[CatalogSettings::KEY] = [
            'mode' => 'evil',
            'exclude_brands' => ['7', 'x', -3, [1]],
            'min_price' => -5,
            'description' => 'full',
            'send_out_of_stock' => 'yes',
        ];

        $catalog = CatalogSettings::fromSettings($this->settings());

        $this->assertSame('all', $catalog->mode);
        $this->assertSame([7], $catalog->excludeBrands);
        $this->assertNull($catalog->minPrice);
        $this->assertSame('full', $catalog->description);
        $this->assertTrue($catalog->sendOutOfStock);
    }

    public function testIdsAreDedupedPositiveInts(): void
    {
        $this->values[CatalogSettings::KEY] = ['include_products' => [3, '3', 0, '12', 12, 5]];

        $this->assertSame([3, 12, 5], CatalogSettings::fromSettings($this->settings())->includeProducts);
    }

    public function testRoundTripThroughSettings(): void
    {
        $catalog = new CatalogSettings(
            mode: 'selected',
            includeCategories: [1, 2],
            includeBrands: [4],
            includeProducts: [9],
            excludeCategories: [5],
            excludeBrands: [6],
            excludeProducts: [7, 8],
            sendOutOfStock: false,
            description: 'full',
            minPrice: 150,
            detailFeatureIds: [11, 12],
        );

        $catalog->toSettings($this->settings());

        $this->assertEquals($catalog, CatalogSettings::fromSettings($this->settings()));
    }

    private function settings(): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn($name) => $this->values[$name] ?? null);
        $settings->method('set')->willReturnCallback(function ($name, $value) use ($settings) {
            $this->values[$name] = $value;

            return $settings;
        });

        return $settings;
    }
}
