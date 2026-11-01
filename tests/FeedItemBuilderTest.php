<?php

namespace Modules\Sviat\MerchantApi;

use InvalidArgumentException;
use LogicException;
use Okay\Modules\Sviat\MerchantApi\Services\FeedItemBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class FeedItemBuilderTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSecondLanguageInSameProcessIsRefused(): void
    {
        FeedItemBuilder::claimLanguage(3);
        FeedItemBuilder::claimLanguage(3);

        $this->expectException(LogicException::class);
        FeedItemBuilder::claimLanguage(1);
    }

    /** @return array<string, array{string}> */
    public static function badSiteUrls(): array
    {
        return [
            'no scheme' => ['shop.example'],
            'ftp' => ['ftp://shop.example'],
            'userinfo' => ['https://user:pass@shop.example'],
            'query' => ['https://shop.example/?a=1'],
            'fragment' => ['https://shop.example/#x'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badSiteUrls')]
    public function testRejectsBadSiteUrl(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        FeedItemBuilder::applySiteUrl($url);
    }
}
