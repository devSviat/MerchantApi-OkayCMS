<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Core\EntityFactory;
use Okay\Entities\ProductsEntity;
use Okay\Helpers\ProductsHelper;

/**
 * Товари з вибору й винятків для списків вкладки «Товари». Форма зберігає лише показані товари, тож тут без
 * вітринного фільтра наявності й без типового ліміту Entity — інакше збереження мовчки губило б частину.
 */
class CatalogProducts
{
    public function __construct(
        private EntityFactory $entityFactory,
        private ProductsHelper $productsHelper,
    ) {
    }

    /**
     * @param list<int> $ids
     * @return array<int, object> за id товару
     */
    public function byId(array $ids): array
    {
        // autoFilter мовчки викидає порожній масив id, і find віддав би весь каталог.
        if ($ids === []) {
            return [];
        }

        $products = [];
        foreach ($this->entityFactory->get(ProductsEntity::class)->noLimit()->find(['id' => $ids]) as $product) {
            $products[(int) $product->id] = $product;
        }

        return $this->productsHelper->attachMainImages($products);
    }
}
