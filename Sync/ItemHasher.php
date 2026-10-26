<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

/**
 * Відбиток ProductInput для порівняння з тим, що вже в Google.
 * Ключі сортуються: порядок полів у записі фіда залежить від порядку розширень модулів, а не від даних.
 */
final class ItemHasher
{
    /** Що видно покупцю й від чого залежить відхилення за невідповідність сторінці — шлеться без затримки. */
    public const array CRITICAL = ['availability', 'availabilityDate', 'price', 'salePrice', 'salePriceEffectiveDate', 'link', 'excludedDestinations'];

    /** @param array<string, mixed> $productInput */
    public static function full(array $productInput): string
    {
        return sha1(self::canonical($productInput));
    }

    /** @param array<string, mixed> $productInput */
    public static function critical(array $productInput): string
    {
        $attributes = $productInput['productAttributes'] ?? [];
        $subset = [];
        foreach (self::CRITICAL as $field) {
            $subset[$field] = $attributes[$field] ?? null;
        }

        return sha1(self::canonical($subset));
    }

    private static function canonical(mixed $value): string
    {
        // Невалідний UTF-8 з будь-якого розширення фіда не має валити весь запуск мови.
        return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
