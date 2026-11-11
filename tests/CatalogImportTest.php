<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use PHPUnit\Framework\TestCase;

/**
 * Одноразове перенесення вибору з фідів GoogleMerchant: після оновлення в Google має йти те саме, що й до нього.
 */
class CatalogImportTest extends TestCase
{
    private const array SETTINGS = [
        'okaycms__google_merchant__upload_non_exists_products_to_google' => '1',
        'okaycms__google_merchant__use_full_description_to_google' => '0',
    ];

    /** Обидва фіди мають усі 12 категорій; бренди тоді нічого не змінюють. */
    public function testAllCategoriesSelectedMeansAllMode(): void
    {
        $relations = [];
        foreach ([1, 2] as $feed) {
            foreach (range(1, 12) as $category) {
                $relations[] = $this->relation($feed, 'category', $category);
            }
            $relations[] = $this->relation($feed, 'brand', 3);
        }

        $catalog = CatalogSettings::fromGoogleMerchant($relations, self::SETTINGS, 12);

        $this->assertSame('all', $catalog->mode);
        $this->assertSame([], $catalog->includeCategories);
        $this->assertSame([], $catalog->includeBrands);
        $this->assertTrue($catalog->sendOutOfStock);
        $this->assertSame('short', $catalog->description);
    }

    public function testPartialCategoriesOrForcedProductsMeanSelectedMode(): void
    {
        $partial = CatalogSettings::fromGoogleMerchant([
            $this->relation(1, 'category', 5),
            $this->relation(2, 'category', 6),
            $this->relation(2, 'brand', 9),
        ], ['okaycms__google_merchant__use_full_description_to_google' => '1'], 12);

        $this->assertSame('selected', $partial->mode);
        $this->assertSame([5, 6], $partial->includeCategories);
        $this->assertSame([9], $partial->includeBrands);
        $this->assertFalse($partial->sendOutOfStock);
        $this->assertSame('full', $partial->description);

        $forced = [];
        foreach (range(1, 12) as $category) {
            $forced[] = $this->relation(1, 'category', $category);
        }
        $forced[] = $this->relation(1, 'product', 77);
        $catalog = CatalogSettings::fromGoogleMerchant($forced, self::SETTINGS, 12);

        $this->assertSame('selected', $catalog->mode);
        $this->assertSame([77], $catalog->includeProducts);
        $this->assertCount(12, $catalog->includeCategories);
    }

    public function testProductExclusionsAreMerged(): void
    {
        $catalog = CatalogSettings::fromGoogleMerchant([
            $this->relation(1, 'product', 10, include: 0),
            $this->relation(2, 'product', 11, include: 0),
            $this->relation(2, 'product', 10, include: 0),
        ], self::SETTINGS, 0);

        $this->assertSame([10, 11], $catalog->excludeProducts);
    }

    /** GoogleMerchant стоїть, але фідів не налаштовано (або чиста установка) — лишаються типові «усі, надсилати». */
    public function testNoRelationsKeepsDefaults(): void
    {
        $this->assertEquals(CatalogSettings::defaults(), CatalogSettings::fromGoogleMerchant([], [], 12));
    }

    /** Оновлення не чіпає вибір, який менеджер уже зберіг у модулі. */
    public function testExistingCatalogSettingsAreNotOverwritten(): void
    {
        $values = [];
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static function ($name) use (&$values) {
            return $values[$name] ?? null;
        });

        $this->assertFalse(CatalogSettings::isStored($settings));
        $values[CatalogSettings::KEY] = ['mode' => 'selected'];
        $this->assertTrue(CatalogSettings::isStored($settings));
    }

    /** @return array{feed_id: int, entity_type: string, entity_id: int, include: int} */
    private function relation(int $feed, string $type, int $id, int $include = 1): array
    {
        return ['feed_id' => $feed, 'entity_type' => $type, 'entity_id' => $id, 'include' => $include];
    }
}
