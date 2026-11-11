<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Core\EntityFactory;
use Okay\Entities\ProductsEntity;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogProducts;
use PHPUnit\Framework\TestCase;

/**
 * Товари-винятки на вкладці «Товари»: форма зберігає лише показані, тож показати треба всі збережені —
 * і поза наявністю, і понад типовий ліміт Entity у 100 записів.
 */
class CatalogProductsTest extends TestCase
{
    public function testLoadsEveryStoredProductWithoutStockFilterOrLimit(): void
    {
        $ids = range(1, 150);
        $entity = $this->createMock(ProductsEntity::class);
        $entity->expects($this->once())->method('noLimit')->willReturnSelf();
        $entity->expects($this->once())->method('find')->with(['id' => $ids])
            ->willReturn(array_map(static fn(int $id) => (object) ['id' => $id, 'name' => "P{$id}", 'main_image_id' => null], array_reverse($ids)));
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);
        $helper = $this->createStub(ProductsHelper::class);
        $helper->method('attachMainImages')->willReturnArgument(0);

        $products = new CatalogProducts($factory, $helper)->byId($ids);

        $this->assertCount(150, $products);
        $this->assertSame('P150', $products[150]->name);
    }

    public function testNoIdsQueriesNothing(): void
    {
        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->never())->method('get');

        $this->assertSame([], new CatalogProducts($factory, $this->createStub(ProductsHelper::class))->byId([]));
    }

    /** Вітринний getList додає фільтр наявності й ліміт — у вкладці «Товари» він губив би винятки при збереженні. */
    public function testAdminDoesNotUseStorefrontProductList(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/MerchantApi/Backend/Controllers/MerchantApiAdmin.php');

        $this->assertStringNotContainsString('->getList(', $source);
    }
}
