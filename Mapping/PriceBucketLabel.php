<?php

namespace Okay\Modules\Sviat\MerchantApi\Mapping;

use InvalidArgumentException;

/**
 * Мітка цінового діапазону (`custom_label_N`) замість правила атрибута в Merchant Center.
 * Діапазони суцільні за верхніми межами: ≤100 → `0-100`, ≤200 → `101-200`, …, понад останню → `3000+`.
 * Підпис нижньої межі — попередня межа + 1, як у правилах, які це замінює (§9 спеки).
 */
final readonly class PriceBucketLabel
{
    /** @var list<int> */
    private array $bounds;

    /** @param list<int> $bounds верхні межі, строго зростають, у гривнях (одиницях валюти) */
    public function __construct(public int $labelIndex, array $bounds)
    {
        if ($labelIndex < 0 || $labelIndex > 4) {
            throw new InvalidArgumentException('custom label index must be 0..4');
        }
        if ($bounds === []) {
            throw new InvalidArgumentException('at least one bound is required');
        }
        $previous = -1;
        foreach ($bounds as $bound) {
            if ($bound <= $previous) {
                throw new InvalidArgumentException('bounds must be increasing non-negative integers');
            }
            $previous = $bound;
        }
        $this->bounds = $bounds;
    }

    /** «100, 200, 400» з поля адмінки; null — порожньо або з помилкою. */
    public static function parseBounds(string $input): ?array
    {
        $parts = preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return null;
        }
        $bounds = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
            $bounds[] = (int) $part;
        }

        return $bounds;
    }

    /** @param string $micros ціна в мікроодиницях (рядок, як у Merchant API) */
    public function labelForMicros(string $micros): string
    {
        $lower = 0;
        foreach ($this->bounds as $bound) {
            if (self::compareMicros($micros, $bound) <= 0) {
                return "{$lower}-{$bound}";
            }
            $lower = $bound + 1;
        }

        return $this->bounds[array_key_last($this->bounds)] . '+';
    }

    public function attributeName(): string
    {
        return 'customLabel' . $this->labelIndex;
    }

    /** Порівняння без float: мікроодиниці — рядок цифр, межа — ціле в одиницях валюти. */
    private static function compareMicros(string $micros, int $units): int
    {
        $micros = ltrim($micros, '0') ?: '0';
        $boundMicros = $units === 0 ? '0' : $units . '000000';

        return strlen($micros) <=> strlen($boundMicros) ?: strcmp($micros, $boundMicros);
    }
}
