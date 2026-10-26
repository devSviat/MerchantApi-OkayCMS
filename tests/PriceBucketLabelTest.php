<?php

namespace Modules\Sviat\MerchantApi;

use InvalidArgumentException;
use Okay\Modules\Sviat\MerchantApi\Mapping\ItemMapper;
use Okay\Modules\Sviat\MerchantApi\Mapping\PriceBucketLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceBucketLabelTest extends TestCase
{
    private const array BOUNDS = [100, 200, 400, 600, 800, 1000, 1500, 2000, 2500, 3000];

    /** @return array<string, array{string, string}> */
    public static function prices(): array
    {
        return [
            'zero' => ['0 UAH', '0-100'],
            'kopiyka' => ['0.01 UAH', '0-100'],
            'upper bound inclusive' => ['100 UAH', '0-100'],
            'fraction above bound' => ['100.5 UAH', '101-200'],
            'next lower bound' => ['101 UAH', '101-200'],
            'middle' => ['999 UAH', '801-1000'],
            'exact 1000' => ['1000 UAH', '801-1000'],
            'last inclusive' => ['3000 UAH', '2501-3000'],
            'just above last' => ['3000.01 UAH', '3000+'],
            'old rule edge 3001' => ['3001 UAH', '3000+'],
            'large' => ['123456 UAH', '3000+'],
        ];
    }

    #[DataProvider('prices')]
    public function testLabels(string $price, string $label): void
    {
        $bucket = new PriceBucketLabel(3, self::BOUNDS);

        $this->assertSame($label, $bucket->labelForMicros(ItemMapper::price($price)['amountMicros']));
    }

    /** Чинне правило Merchant Center: включні діапазони з дірками; для цілих цін результат має збігатися. */
    public function testMatchesMerchantCenterRuleForWholePrices(): void
    {
        $bucket = new PriceBucketLabel(3, self::BOUNDS);
        $rule = [[0, 100], [101, 200], [201, 400], [401, 600], [601, 800], [801, 1000], [1001, 1500], [1501, 2000], [2001, 2500], [2501, 3000]];

        for ($price = 0; $price <= 3100; $price++) {
            $expected = null;
            foreach ($rule as [$from, $to]) {
                if ($price >= $from && $price <= $to) {
                    $expected = "{$from}-{$to}";
                }
            }
            if ($price > 3001) {
                $expected = '3000+';
            }
            if ($expected === null) {
                // 3001: у чинному правилі «більше ніж 3001» не включає 3001 — дірка, яку модуль закриває.
                $this->assertSame(3001, $price);
                continue;
            }
            $this->assertSame($expected, $bucket->labelForMicros($price . '000000'), "price {$price}");
        }
    }

    public function testParseBounds(): void
    {
        $this->assertSame(self::BOUNDS, PriceBucketLabel::parseBounds("100, 200,400 600;800\n1000 1500 2000 2500 3000"));
        $this->assertNull(PriceBucketLabel::parseBounds(''));
        $this->assertNull(PriceBucketLabel::parseBounds('100, 2.5'));
        $this->assertNull(PriceBucketLabel::parseBounds('100, -5'));
    }

    /** @return array<string, array{int, list<int>}> */
    public static function invalid(): array
    {
        return [
            'index 5' => [5, [100]],
            'negative index' => [-1, [100]],
            'empty' => [3, []],
            'not increasing' => [3, [100, 100]],
            'decreasing' => [3, [200, 100]],
        ];
    }

    /** @param list<int> $bounds */
    #[DataProvider('invalid')]
    public function testRejectsBadConfig(int $index, array $bounds): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PriceBucketLabel($index, $bounds);
    }
}
