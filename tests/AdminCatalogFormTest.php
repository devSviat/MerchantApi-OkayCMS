<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\FeaturesEntity;
use Okay\Modules\Sviat\MerchantApi\Backend\Controllers\MerchantApiAdmin;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogSettings;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Sync\StatusPoller;
use PHPUnit\Framework\TestCase;

/** Вкладка «Товари» і правки адмінки 1.1. */
class AdminCatalogFormTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** Системний список товарів шле порожній рядок-заглушку, коли товарів немає. */
    public function testSaveCatalogParsesForm(): void
    {
        $catalog = CatalogSettings::fromForm([
            'mode' => 'selected',
            'include_categories' => ['3', '5'],
            'include_brands' => ['7'],
            'include_products' => ['', '42'],
            'exclude_categories' => [],
            'exclude_brands' => ['36'],
            'exclude_products' => [''],
            'send_out_of_stock' => '0',
            'description' => 'full',
            'min_price' => ' 150 ',
            'detail_feature_ids' => ['9'],
        ]);

        $this->assertNotNull($catalog);
        $this->assertSame('selected', $catalog->mode);
        $this->assertSame([3, 5], $catalog->includeCategories);
        $this->assertSame([42], $catalog->includeProducts);
        $this->assertSame([36], $catalog->excludeBrands);
        $this->assertSame([], $catalog->excludeProducts);
        $this->assertFalse($catalog->sendOutOfStock);
        $this->assertSame('full', $catalog->description);
        $this->assertSame(150, $catalog->minPrice);
        $this->assertSame([9], $catalog->detailFeatureIds);
    }

    public function testUnknownModeOrDescriptionIsRejected(): void
    {
        $this->assertNull(CatalogSettings::fromForm(['mode' => 'evil', 'description' => 'short']));
        $this->assertNull(CatalogSettings::fromForm(['mode' => 'all', 'description' => 'html']));
        $this->assertNull(CatalogSettings::fromForm(['mode' => 'all', 'description' => 'short', 'min_price' => 'abc']));
        $this->assertNull(CatalogSettings::fromForm(['mode' => 'all', 'description' => 'short', 'min_price' => '']) ?->minPrice);
    }

    public function testFeedColumnGone(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/MerchantApi/Backend/design/html/merchant_api.tpl');

        $this->assertStringNotContainsString('[feed_id]', $template);
        $this->assertStringNotContainsString('ma_feeds', $template);
    }

    /** Кнопка «Оновити статуси» лише читає Google — працює й на паузі, коли перехід ще не ввімкнено. */
    public function testPollWorksWhenPausedOnManualRequest(): void
    {
        $this->assertFalse(StatusPoller::shouldPoll(paused: true, manual: false, connected: true));
        $this->assertTrue(StatusPoller::shouldPoll(paused: true, manual: true, connected: true));
        $this->assertFalse(StatusPoller::shouldPoll(paused: false, manual: true, connected: false));
    }

    /** Google віддає щойно створене джерело у списку із затримкою — модуль запам'ятовує його сам. */
    public function testCreatedSourceIsCachedImmediately(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        $settings->setSourcesCache([['name' => 'accounts/1/dataSources/10', 'input' => 'FILE', 'primaryProductDataSource' => ['contentLanguage' => 'ru-UA', 'feedLabel' => 'LABEL_RU']]], new DateTimeImmutable('2026-11-11T10:00:00+02:00'));

        $settings->rememberSource(['name' => 'accounts/1/dataSources/20', 'displayName' => 'API RU', 'input' => 'API', 'primaryProductDataSource' => ['contentLanguage' => 'ru', 'feedLabel' => 'LABEL_RU', 'countries' => ['UA']]]);
        $settings->rememberSource(['name' => 'accounts/1/dataSources/20', 'displayName' => 'API RU', 'input' => 'API', 'primaryProductDataSource' => ['contentLanguage' => 'ru', 'feedLabel' => 'LABEL_RU']]);

        $sources = $settings->sourcesCache()['sources'];
        $this->assertSame(['accounts/1/dataSources/10', 'accounts/1/dataSources/20'], array_column($sources, 'name'));
        $this->assertSame('LABEL_RU', $sources[1]['feed_label']);
    }

    /** Повідомлення показується в секції, де натиснули дію, а не вгорі сторінки. */
    public function testMessageCarriesItsSection(): void
    {
        $this->assertSame(['access', 'connection_ok'], MerchantApiAdmin::splitMessage('access:connection_ok'));
        $this->assertSame([null, 'saved'], MerchantApiAdmin::splitMessage('saved'));
        $this->assertSame([null, 'evil:x'], MerchantApiAdmin::splitMessage('evil:x'));
        $this->assertSame('catalog', MerchantApiAdmin::sectionOf('save_catalog'));
        $this->assertNull(MerchantApiAdmin::sectionOf('unknown'));
    }

    /** Збирач бере характеристики лише з видимих властивостей — прихованої у виборі бути не може. */
    public function testDetailFeaturePickerListsOnlyVisibleFeatures(): void
    {
        $features = $this->createMock(FeaturesEntity::class);
        $features->expects($this->once())->method('noLimit')->willReturnSelf();
        $features->expects($this->once())->method('find')->with(['visible' => 1])->willReturn([]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($features);

        $this->assertSame([], MerchantApiAdmin::detailFeatures($factory));
    }

    private function settingsStub(): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn($name) => $this->values[$name] ?? null);
        $settings->method('set')->willReturnCallback(function ($name, $value) use ($settings) {
            $this->values[$name] = $value;

            return $settings;
        });

        return $settings;
    }
}
