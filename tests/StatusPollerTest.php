<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Services\LanguageTarget;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Sync\StatusPoller;
use PHPUnit\Framework\TestCase;

class StatusPollerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function testProductWithoutIssuesIsSkipped(): void
    {
        $this->assertNull(StatusPoller::issueRow(['offerId' => '1', 'productStatus' => ['itemLevelIssues' => [
            ['severity' => 'NOT_IMPACTED', 'code' => 'x', 'description' => 'info only'],
        ]]]));
        $this->assertNull(StatusPoller::issueRow(['offerId' => '1']));
    }

    public function testWorstSeverityWinsAndTextIsCleaned(): void
    {
        $row = StatusPoller::issueRow([
            'offerId' => '42',
            'contentLanguage' => 'ru',
            'feedLabel' => 'LANG_X',
            'productStatus' => ['itemLevelIssues' => [
                ['severity' => 'DEMOTED', 'code' => 'image_too_small', 'attribute' => 'image link', 'description' => "Мале\nзображення"],
                ['severity' => 'DISAPPROVED', 'code' => 'price_mismatch', 'description' => str_repeat('x', 400), 'reportingContext' => 'SHOPPING_ADS'],
            ]],
        ]);

        $this->assertSame('DISAPPROVED', $row['severity']);
        $this->assertSame(['42', 'ru', 'LANG_X'], [$row['offer_id'], $row['content_language'], $row['feed_label']]);
        $issues = json_decode($row['issues'], true);
        $this->assertSame('Мале зображення', $issues[0]['description']);
        $this->assertSame(300, mb_strlen($issues[1]['description']));
    }

    /** Кнопка «Оновити статуси й кількість» перечитує й список джерел: інакше перейменоване джерело видно лише після «Перевірити зв’язок». */
    public function testPollRefreshesDataSourceList(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        $settings->setAccountId('123');
        new \Okay\Modules\Sviat\MerchantApi\Services\CredentialStore($this->settingsStub())->saveJson(TestKeys::json(), new DateTimeImmutable());
        $google = new FakeGoogle();
        $google->dataSources = [['name' => 'accounts/123/dataSources/20', 'displayName' => 'Shop — API UA', 'input' => 'API', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'feedLabel' => '-']]];
        $clients = new class ($google) extends \Okay\Modules\Sviat\MerchantApi\Google\MerchantClientFactory {
            public function __construct(private FakeGoogle $google)
            {
                parent::__construct($google);
            }

            public function create(\Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey $key, string $accountId): \Okay\Modules\Sviat\MerchantApi\Google\MerchantClient
            {
                return new \Okay\Modules\Sviat\MerchantApi\Google\MerchantClient(new \Okay\Modules\Sviat\MerchantApi\Google\GoogleAuth($key, $this->google, fn() => 1_800_000_000), $this->google, $accountId, fn() => null);
            }
        };
        $entities = $this->createStub(\Okay\Core\EntityFactory::class);
        $entities->method('get')->willReturnCallback(function (string $class) {
            $entity = $this->createStub($class);
            $entity->method('cols')->willReturnSelf();
            $entity->method('noLimit')->willReturnSelf();
            $entity->method('find')->willReturn([]);

            return $entity;
        });

        new StatusPoller($settings, new \Okay\Modules\Sviat\MerchantApi\Services\CredentialStore($this->settingsStub()), $clients, $entities, new \Okay\Modules\Sviat\MerchantApi\Sync\Clock())->poll(manual: true);

        $this->assertSame(['Shop — API UA'], array_column($settings->sourcesCache()['sources'], 'display_name'));
    }

    public function testSourcesCacheKeepsOnlyValidResourceNames(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        $settings->setSourcesCache([
            ['name' => 'accounts/1/dataSources/2', 'displayName' => 'API UK', 'input' => 'API', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'feedLabel' => 'UA', 'countries' => ['UA']]],
            ['name' => 'accounts/1/dataSources/3', 'displayName' => 'File', 'input' => 'FILE', 'primaryProductDataSource' => []],
            ['name' => '../../evil', 'displayName' => 'x'],
            ['name' => 'accounts/1/dataSources/4', 'displayName' => 'Supplemental', 'input' => 'API', 'supplementalProductDataSource' => []],
        ], new DateTimeImmutable('2026-10-26T12:00:00+02:00'));

        $cache = $settings->sourcesCache();
        $this->assertSame(['accounts/1/dataSources/2', 'accounts/1/dataSources/3', 'accounts/1/dataSources/4'], array_column($cache['sources'], 'name'));
        $this->assertSame([true, true, false], array_column($cache['sources'], 'primary'));
        $this->assertSame('uk', $cache['sources'][0]['content_language']);

        $this->values[ModuleSettings::PREFIX . 'sources_cache'] = ['sources' => [['name' => 'accounts/1/dataSources/9'], 'junk', ['name' => 'x']]];
        $this->assertSame([[
            'name' => 'accounts/1/dataSources/9', 'display_name' => '', 'input' => '', 'content_language' => null,
            'feed_label' => null, 'countries' => [], 'primary' => false, 'destinations' => [],
        ]], $settings->sourcesCache()['sources']);
    }

    public function testSettingsRejectCorruptedValues(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        $this->values = [
            ModuleSettings::PREFIX . 'account_id' => '12/../34',
            ModuleSettings::PREFIX . 'site_url' => 'javascript:alert(1)',
            ModuleSettings::PREFIX . 'languages' => [['lang_id' => 3, 'content_language' => 'uk', 'feed_label' => 'bad label', 'feed_id' => 1]],
            ModuleSettings::PREFIX . 'countries' => 'ua, XXX, 1',
        ];

        $this->assertNull($settings->accountId());
        $this->assertNull($settings->siteUrl());
        $this->assertSame([], $settings->languages());
        $this->assertSame(['UA'], $settings->countries());
        $this->assertTrue($settings->isPaused(), 'unset pause means paused');
        $this->assertTrue($settings->isDryRun(), 'unset dry run means dry run');
        $this->assertNull(LanguageTarget::create(3, 'uk', 'UA', 'accounts/1/dataSources/2?x=1'));
    }

    /** Ядро десеріалізує кожне налаштування: рядок із форми може повернутись об'єктом. */
    public function testObjectFromSettingsDoesNotBreakReading(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        foreach (['account_id', 'site_url', 'countries', 'price_label_index', 'price_label_bounds', 'paused', 'dry_run', 'ops_per_run'] as $name) {
            $this->values[ModuleSettings::PREFIX . $name] = new \stdClass();
        }

        $this->assertNull($settings->accountId());
        $this->assertNull($settings->siteUrl());
        $this->assertSame(['UA'], $settings->countries());
        $this->assertNull($settings->priceLabel());
        $this->assertTrue($settings->isPaused());
        $this->assertSame(ModuleSettings::DEFAULT_OPS_PER_RUN, $settings->opsPerRun());
    }

    public function testOnlyValidValuesAreStored(): void
    {
        $settings = new ModuleSettings($this->settingsStub());

        $this->assertFalse($settings->setSiteUrl('https://shop.example:abc'));
        $this->assertSame('', $this->values[ModuleSettings::PREFIX . 'site_url']);
        $this->assertTrue($settings->setSiteUrl('https://shop.example:8443/sub/'));
        $this->assertSame('https://shop.example:8443/sub', $settings->siteUrl());
        $this->assertFalse($settings->setAccountId('O:8:"stdClass":0:{}'));
        $this->assertSame('', $this->values[ModuleSettings::PREFIX . 'account_id']);
        $settings->setCountries('ua; pl, xx1');
        $this->assertSame('UA,PL', $this->values[ModuleSettings::PREFIX . 'countries']);
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
