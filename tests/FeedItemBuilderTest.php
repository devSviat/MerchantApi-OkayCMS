<?php

namespace Modules\Sviat\MerchantApi;

use InvalidArgumentException;
use LogicException;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogItemBuilder;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use Okay\Modules\Sviat\MerchantApi\Services\FeedItemBuilder;
use Okay\Modules\Sviat\MerchantApi\Services\LanguageTarget;
use Okay\Modules\Sviat\MerchantApi\Services\Requirements;
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

    /** Налаштування мов зі старих версій містять feed_id — мова й мітка мають читатися як раніше. */
    public function testLegacyLanguageRowWithFeedIdStillLoads(): void
    {
        $target = LanguageTarget::fromArray(['lang_id' => 3, 'content_language' => 'uk', 'feed_label' => '-', 'feed_id' => 1, 'data_source' => 'accounts/1/dataSources/2']);

        $this->assertNotNull($target);
        $this->assertSame(['lang_id' => 3, 'content_language' => 'uk', 'feed_label' => '-', 'data_source' => 'accounts/1/dataSources/2', 'removing' => false], $target->toArray());
    }

    /**
     * «Не надсилати» стосується підсумкової наявності після розширень: «під замовлення» лишається,
     * а «немає в наявності» (зокрема знятий з виробництва) відсіюється.
     */
    public function testOutOfStockSkippedOnlyWhenAvailabilityIsOutOfStock(): void
    {
        $skip = new CatalogSettings(sendOutOfStock: false);
        $send = new CatalogSettings();

        $this->assertSame('out_of_stock', FeedItemBuilder::skipReason($this->item('out of stock', '500 UAH'), $skip));
        $this->assertNull(FeedItemBuilder::skipReason($this->item('backorder', '500 UAH'), $skip));
        $this->assertNull(FeedItemBuilder::skipReason($this->item('in stock', '500 UAH'), $skip));
        $this->assertNull(FeedItemBuilder::skipReason($this->item('out of stock', '500 UAH'), $send));
    }

    /** Модуль-розширення може зняти запис зі збірки з відомою лічильнику причиною; невідома ігнорується. */
    public function testExtensionCanSkipItemWithKnownReason(): void
    {
        $item = $this->item('in stock', '500 UAH');

        $this->assertSame('discontinued', FeedItemBuilder::skipReason([CatalogItemBuilder::SKIP => 'discontinued'] + $item, new CatalogSettings()));
        $this->assertNull(FeedItemBuilder::skipReason([CatalogItemBuilder::SKIP => 'whatever'] + $item, new CatalogSettings()));
    }

    /** Порівнюється ціна, за якою товар продається: акційна, якщо вона є. */
    public function testMinPriceUsesSalePriceWhenPresent(): void
    {
        $catalog = new CatalogSettings(minPrice: 400);

        $this->assertNull(FeedItemBuilder::skipReason($this->item('in stock', '400 UAH'), $catalog));
        $this->assertSame('min_price', FeedItemBuilder::skipReason($this->item('in stock', '399.99 UAH'), $catalog));
        $this->assertSame('min_price', FeedItemBuilder::skipReason($this->item('in stock', '500 UAH', '350 UAH'), $catalog));
        $this->assertNull(FeedItemBuilder::skipReason($this->item('in stock', '100 UAH'), new CatalogSettings()));
    }

    /**
     * Перевірка за кодом, а не за missing(): у репозиторії GoogleMerchant встановлений, тож відсутність вимоги
     * не видно з результату. Модуль має обходитися без нього, щоб його можна було видалити.
     */
    public function testModuleCodeNoLongerUsesGoogleMerchant(): void
    {
        $root = dirname((new \ReflectionClass(Requirements::class))->getFileName(), 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $this->assertStringNotContainsString('OkayCMS\\GoogleMerchant', (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    /** @return array<string, array{data: string}> */
    private function item(string $availability, string $price, ?string $salePrice = null): array
    {
        return ['g:availability' => ['data' => $availability], 'g:price' => ['data' => $price]]
            + ($salePrice !== null ? ['g:sale_price' => ['data' => $salePrice]] : []);
    }
}
