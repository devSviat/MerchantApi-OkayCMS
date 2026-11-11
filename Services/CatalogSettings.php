<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Core\Settings;

/**
 * Які товари йдуть у Google і як збираються їхні дані. Один набір на всі мови сайту: асортимент від мови
 * не залежить, а окремі списки для кожної мови рано чи пізно розійшлись би.
 */
final readonly class CatalogSettings
{
    public const string KEY = 'sviat__merchant_api__catalog';

    public const string MODE_ALL = 'all';
    public const string MODE_SELECTED = 'selected';
    public const string DESCRIPTION_SHORT = 'short';
    public const string DESCRIPTION_FULL = 'full';

    /**
     * @param list<int> $includeCategories
     * @param list<int> $includeBrands
     * @param list<int> $includeProducts
     * @param list<int> $excludeCategories
     * @param list<int> $excludeBrands
     * @param list<int> $excludeProducts
     * @param list<int> $detailFeatureIds властивості, що йдуть у Google як характеристики (product_detail)
     */
    public function __construct(
        public string $mode = self::MODE_ALL,
        public array $includeCategories = [],
        public array $includeBrands = [],
        public array $includeProducts = [],
        public array $excludeCategories = [],
        public array $excludeBrands = [],
        public array $excludeProducts = [],
        public bool $sendOutOfStock = true,
        public string $description = self::DESCRIPTION_SHORT,
        public ?int $minPrice = null,
        public array $detailFeatureIds = [],
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    /** Зіпсоване поле бере типове значення, решта збереженого лишається. */
    public static function fromSettings(Settings $settings): self
    {
        $raw = $settings->get(self::KEY);

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /** @param array<mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $defaults = self::defaults();
        $minPrice = $raw['min_price'] ?? null;

        return new self(
            mode: in_array($raw['mode'] ?? null, [self::MODE_ALL, self::MODE_SELECTED], true) ? $raw['mode'] : $defaults->mode,
            includeCategories: self::ids($raw['include_categories'] ?? null),
            includeBrands: self::ids($raw['include_brands'] ?? null),
            includeProducts: self::ids($raw['include_products'] ?? null),
            excludeCategories: self::ids($raw['exclude_categories'] ?? null),
            excludeBrands: self::ids($raw['exclude_brands'] ?? null),
            excludeProducts: self::ids($raw['exclude_products'] ?? null),
            sendOutOfStock: is_bool($raw['send_out_of_stock'] ?? null) ? $raw['send_out_of_stock'] : $defaults->sendOutOfStock,
            description: in_array($raw['description'] ?? null, [self::DESCRIPTION_SHORT, self::DESCRIPTION_FULL], true) ? $raw['description'] : $defaults->description,
            minPrice: is_int($minPrice) && $minPrice > 0 ? $minPrice : null,
            detailFeatureIds: self::ids($raw['detail_feature_ids'] ?? null),
        );
    }

    /**
     * Форма вкладки «Товари». Невідомий режим, опис чи нецілу мінімальну ціну відхиляє цілком (null): мовчки
     * підставлене типове значення змінило б набір товарів у Google не так, як хотів менеджер.
     *
     * @param array<mixed> $post
     */
    public static function fromForm(array $post): ?self
    {
        $mode = $post['mode'] ?? null;
        $description = $post['description'] ?? null;
        $minPrice = trim((string) ($post['min_price'] ?? ''));
        if (!in_array($mode, [self::MODE_ALL, self::MODE_SELECTED], true)
            || !in_array($description, [self::DESCRIPTION_SHORT, self::DESCRIPTION_FULL], true)
            || ($minPrice !== '' && !ctype_digit($minPrice))) {
            return null;
        }

        return new self(
            mode: $mode,
            includeCategories: self::ids($post['include_categories'] ?? null),
            includeBrands: self::ids($post['include_brands'] ?? null),
            includeProducts: self::ids($post['include_products'] ?? null),
            excludeCategories: self::ids($post['exclude_categories'] ?? null),
            excludeBrands: self::ids($post['exclude_brands'] ?? null),
            excludeProducts: self::ids($post['exclude_products'] ?? null),
            sendOutOfStock: ($post['send_out_of_stock'] ?? '1') === '1',
            description: $description,
            minPrice: $minPrice !== '' && (int) $minPrice > 0 ? (int) $minPrice : null,
            detailFeatureIds: self::ids($post['detail_feature_ids'] ?? null),
        );
    }

    /**
     * Вибір із фідів OkayCMS/GoogleMerchant — один раз при оновленні, щоб у Google пішло те саме, що й до нього.
     * Фід GM включав товар за категорією (з нащадками), брендом або самим товаром; якщо в кожному фіді вибрано всі
     * категорії й немає примусово включених товарів, це фактично «усі товари». Без жодного зв'язку (чиста установка
     * або GM без налаштованих фідів) переносити нічого — лишаються типові значення.
     *
     * @param iterable<array{feed_id: int|string, entity_type: string, entity_id: int|string, include: int|string}> $relations
     * @param array<string, mixed> $gmSettings налаштування GM (ключі okaycms__google_merchant__*)
     */
    public static function fromGoogleMerchant(iterable $relations, array $gmSettings, int $allCategoriesCount): self
    {
        $categoriesByFeed = [];
        $categories = [];
        $brands = [];
        $includeProducts = [];
        $excludeProducts = [];
        foreach ($relations as $relation) {
            $id = (int) $relation['entity_id'];
            match (true) {
                $relation['entity_type'] === 'category' => [$categories[$id] = $id, $categoriesByFeed[(int) $relation['feed_id']][$id] = true],
                $relation['entity_type'] === 'brand' => $brands[$id] = $id,
                $relation['entity_type'] === 'product' && (int) $relation['include'] === 1 => $includeProducts[$id] = $id,
                $relation['entity_type'] === 'product' => $excludeProducts[$id] = $id,
                default => null,
            };
        }

        if ($categoriesByFeed === [] && $brands === [] && $includeProducts === [] && $excludeProducts === []) {
            return self::defaults();
        }

        $everyFeedHasAllCategories = $categoriesByFeed !== []
            && array_filter($categoriesByFeed, static fn(array $ids) => count($ids) < $allCategoriesCount) === [];
        $selected = !$everyFeedHasAllCategories || $includeProducts !== [];

        return new self(
            mode: $selected ? self::MODE_SELECTED : self::MODE_ALL,
            includeCategories: $selected ? array_values($categories) : [],
            includeBrands: $selected ? array_values($brands) : [],
            includeProducts: array_values($includeProducts),
            excludeProducts: array_values($excludeProducts),
            sendOutOfStock: !empty($gmSettings['okaycms__google_merchant__upload_non_exists_products_to_google']),
            description: !empty($gmSettings['okaycms__google_merchant__use_full_description_to_google']) ? self::DESCRIPTION_FULL : self::DESCRIPTION_SHORT,
        );
    }

    public static function isStored(Settings $settings): bool
    {
        return is_array($settings->get(self::KEY));
    }

    public function toSettings(Settings $settings): void
    {
        $settings->set(self::KEY, $this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'include_categories' => $this->includeCategories,
            'include_brands' => $this->includeBrands,
            'include_products' => $this->includeProducts,
            'exclude_categories' => $this->excludeCategories,
            'exclude_brands' => $this->excludeBrands,
            'exclude_products' => $this->excludeProducts,
            'send_out_of_stock' => $this->sendOutOfStock,
            'description' => $this->description,
            'min_price' => $this->minPrice,
            'detail_feature_ids' => $this->detailFeatureIds,
        ];
    }

    /** @return list<int> */
    private static function ids(mixed $raw): array
    {
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }
}
