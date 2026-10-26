<?php

namespace Okay\Modules\Sviat\MerchantApi\Mapping;

use DateTimeImmutable;

/**
 * Запис фіда Google (масив `getItem` після розширень модулів) → ProductInput Merchant API v1.
 * https://developers.google.com/merchant/api/reference/rest/products_v1/accounts.productInputs
 *
 * Невідомий тег чи значення не відкидається мовчки: потрапляє в MappedItem::$problems,
 * бо інакше новий атрибут від іншого модуля непомітно зник би з Google.
 */
final readonly class ItemMapper
{
    private const array AVAILABILITY = [
        'in stock' => 'IN_STOCK',
        'out of stock' => 'OUT_OF_STOCK',
        'backorder' => 'BACKORDER',
        'preorder' => 'PREORDER',
    ];

    private const array CONDITION = ['new' => 'NEW', 'used' => 'USED', 'refurbished' => 'REFURBISHED'];

    private const array DESTINATIONS = [
        'Shopping_ads' => 'SHOPPING_ADS',
        'Display_ads' => 'DISPLAY_ADS',
        'Local_inventory_ads' => 'LOCAL_INVENTORY_ADS',
        'Free_listings' => 'FREE_LISTINGS',
        'Free_local_listings' => 'FREE_LOCAL_LISTINGS',
    ];

    /** Рядкові атрибути без перетворення: тег фіда → поле productAttributes. */
    private const array PLAIN = [
        'title' => 'title',
        'description' => 'description',
        'link' => 'link',
        'g:image_link' => 'imageLink',
        'g:brand' => 'brand',
        'g:mpn' => 'mpn',
        'g:color' => 'color',
        'g:size' => 'size',
        'g:item_group_id' => 'itemGroupId',
        'g:google_product_category' => 'googleProductCategory',
        'g:custom_label_0' => 'customLabel0',
        'g:custom_label_1' => 'customLabel1',
        'g:custom_label_2' => 'customLabel2',
        'g:custom_label_3' => 'customLabel3',
        'g:custom_label_4' => 'customLabel4',
    ];

    /**
     * Ліміти Google на довжину (символів). Як Merchant API поводиться з довшим текстом, не перевірено —
     * обрізаємо, щоб товар не відхилили через це.
     */
    private const array MAX_LENGTH = ['title' => 150, 'description' => 5000];

    public function __construct(private ?PriceBucketLabel $priceLabel = null)
    {
    }

    /**
     * @param array<int|string, mixed> $feedItem запис як із GoogleMerchantHelper::getItem (значення HTML-екрановані)
     */
    public function fromFeedItem(array $feedItem, string $contentLanguage, string $feedLabel): MappedItem
    {
        $flat = [];
        $problems = [];
        foreach ($feedItem as $key => $entry) {
            $tag = is_array($entry) && isset($entry['tag']) ? (string) $entry['tag'] : (string) $key;
            $value = is_array($entry) ? ($entry['data'] ?? null) : null;
            if (!is_scalar($value)) {
                $problems[] = "unsupported:{$tag}";
                continue;
            }
            // Фід екранує через htmlspecialchars(strip_tags()); API чекає звичайний текст.
            $flat[$tag][] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $this->map($flat, $contentLanguage, $feedLabel, $problems);
    }

    /**
     * @param array<string, list<string>> $flat тег → значення вже звичайним текстом (так дає і розбір XML)
     * @param list<string> $problems
     */
    public function map(array $flat, string $contentLanguage, string $feedLabel, array $problems = []): MappedItem
    {
        $offerId = $flat['g:id'][0] ?? '';
        unset($flat['g:id']);
        $attributes = [];

        foreach ($flat as $tag => $values) {
            $first = $values[0];
            switch (true) {
                case isset(self::PLAIN[$tag]):
                    $attributes[self::PLAIN[$tag]] = isset(self::MAX_LENGTH[$tag]) ? mb_substr($first, 0, self::MAX_LENGTH[$tag]) : $first;
                    break;
                case $tag === 'g:additional_image_link':
                    $attributes['additionalImageLinks'] = $values;
                    break;
                case $tag === 'g:product_type':
                    $attributes['productTypes'] = $values;
                    break;
                case $tag === 'g:price' || $tag === 'g:sale_price':
                    $price = self::price($first);
                    if ($price === null) {
                        $problems[] = "bad_value:{$tag}";
                        break;
                    }
                    $attributes[$tag === 'g:price' ? 'price' : 'salePrice'] = $price;
                    break;
                case $tag === 'g:availability':
                    $availability = self::AVAILABILITY[$first] ?? null;
                    $availability === null ? $problems[] = "bad_value:{$tag}" : $attributes['availability'] = $availability;
                    break;
                case $tag === 'g:availability_date':
                    $date = self::timestamp($first);
                    $date === null ? $problems[] = "bad_value:{$tag}" : $attributes['availabilityDate'] = $date;
                    break;
                case $tag === 'g:sale_price_effective_date':
                    $interval = self::interval($first);
                    $interval === null ? $problems[] = "bad_value:{$tag}" : $attributes['salePriceEffectiveDate'] = $interval;
                    break;
                case $tag === 'g:excluded_destination':
                    foreach ($values as $value) {
                        if (!isset(self::DESTINATIONS[$value])) {
                            $problems[] = "bad_value:{$tag}";
                            continue;
                        }
                        $attributes['excludedDestinations'][] = self::DESTINATIONS[$value];
                    }
                    break;
                case $tag === 'g:condition':
                    $condition = self::CONDITION[$first] ?? null;
                    $condition === null ? $problems[] = "bad_value:{$tag}" : $attributes['condition'] = $condition;
                    break;
                case $tag === 'g:adult':
                    $attributes['adult'] = $first === 'true';
                    break;
                case $tag === 'g:gtin':
                    $attributes['gtins'] = $values;
                    break;
                default:
                    $problems[] = "unknown_tag:{$tag}";
            }
        }

        if ($this->priceLabel !== null) {
            $basis = $attributes['salePrice'] ?? $attributes['price'] ?? null;
            if ($basis !== null) {
                $attributes[$this->priceLabel->attributeName()] = $this->priceLabel->labelForMicros($basis['amountMicros']);
            }
        }

        if ($offerId === '') {
            $problems[] = 'missing:g:id';
        }

        return new MappedItem($offerId, [
            'offerId' => $offerId,
            'contentLanguage' => $contentLanguage,
            'feedLabel' => $feedLabel,
            'productAttributes' => $attributes,
        ], $problems);
    }

    /** Фід пише `1299.5 UAH`; мікроодиниці — рядком, без float. */
    public static function price(string $value): ?array
    {
        if (!preg_match('/\A(\d{1,12})(?:\.(\d{1,6}))? ([A-Z]{3})\z/', trim($value), $m)) {
            return null;
        }
        $micros = ltrim($m[1] . str_pad($m[2], 6, '0'), '0');

        return ['amountMicros' => $micros === '' ? '0' : $micros, 'currencyCode' => $m[3]];
    }

    /** Формат фіда `2026-11-16T12:00+0200` (або з секундами) → RFC 3339, якого чекає API. */
    public static function timestamp(string $value): ?string
    {
        // `p` — це `Z` для UTC: так пише Sviat/Promo (gmdate('…\Z')).
        foreach (['Y-m-d\TH:iO', 'Y-m-d\TH:i:sO', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sp'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format(DATE_RFC3339);
            }
        }

        return null;
    }

    /** @return array{startTime: string, endTime: string}|null */
    public static function interval(string $value): ?array
    {
        $parts = explode('/', $value);
        if (count($parts) !== 2) {
            return null;
        }
        $start = self::timestamp($parts[0]);
        $end = self::timestamp($parts[1]);

        return $start !== null && $end !== null ? ['startTime' => $start, 'endTime' => $end] : null;
    }
}
