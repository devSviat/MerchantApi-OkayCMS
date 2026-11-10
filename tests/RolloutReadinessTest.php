<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Google\ApiError;
use Okay\Modules\Sviat\MerchantApi\Google\GoogleAuth;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClient;
use Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use Okay\Modules\Sviat\MerchantApi\Services\FileSourceMatch;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Sync\StatusPoller;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestKeys.php';
require_once __DIR__ . '/FakeGoogle.php';

/**
 * Перехід із файлових джерел на API: товар у Google — це мова~мітка~ID, тож мітка й способи показу
 * мусять прийти з чинного файлового джерела, а помилки доступу — бути зрозумілими людині.
 */
class RolloutReadinessTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** Причини квот Google містять «/» (quota/request_rate_too_high) і не мають підмінятися на HTTP_429. */
    public function testQuotaReasonWithSlashIsKept(): void
    {
        $error = ApiError::fromResponse(FakeGoogle::error(429, 'RESOURCE_EXHAUSTED', 'quota/request_rate_too_high', 'Too many requests'));

        $this->assertSame('quota/request_rate_too_high', $error->reason);
        $this->assertSame('429:quota/request_rate_too_high: Too many requests', $error->describe());
    }

    public function testDescribeOmitsEmptyMessage(): void
    {
        $this->assertSame('401:UNAUTHENTICATED', ApiError::fromResponse(new HttpResponse(401, json_encode(['error' => ['status' => 'UNAUTHENTICATED']])))->describe());
    }

    public function testNewSourceCopiesDestinationsOfTheFileSource(): void
    {
        $google = new FakeGoogle();
        $google->failures = [new HttpResponse(200, '{"name":"accounts/123/dataSources/9"}')];
        $destinations = [['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED'], ['destination' => 'LOCAL_CLOUD_RETAIL', 'state' => 'DISABLED']];

        $this->client($google)->createApiDataSource('Shop API UK', 'uk', '-', ['UA'], $destinations);

        $body = json_decode((string) end($google->requests)['body'], true);
        $this->assertSame($destinations, $body['primaryProductDataSource']['destinations']);
        $this->assertSame('-', $body['primaryProductDataSource']['feedLabel']);
    }

    public function testWithoutFileSourceGoogleDefaultsStay(): void
    {
        $google = new FakeGoogle();
        $google->failures = [new HttpResponse(200, '{"name":"accounts/123/dataSources/9"}')];

        $this->client($google)->createApiDataSource('Shop API UK', 'uk', 'UA', ['UA']);

        $this->assertArrayNotHasKey('destinations', json_decode((string) end($google->requests)['body'], true)['primaryProductDataSource']);
    }

    /** Файлові джерела так, як їх віддає API: мітка буває «-», мова — з регіоном («ru-UA»), а товари в ньому — «ru». */
    public function testFileSourceIsFoundByTwoLetterLanguage(): void
    {
        $sources = $this->cachedSources([
            ['name' => 'accounts/1/dataSources/10', 'displayName' => 'Feed UA', 'input' => 'FILE', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'feedLabel' => '-', 'destinations' => [['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED']]]],
            ['name' => 'accounts/1/dataSources/11', 'displayName' => 'Feed RU', 'input' => 'FILE', 'primaryProductDataSource' => ['contentLanguage' => 'ru-UA', 'feedLabel' => 'LANG_RU']],
            ['name' => 'accounts/1/dataSources/12', 'displayName' => 'Site', 'input' => 'AUTOFEED', 'primaryProductDataSource' => []],
            ['name' => 'accounts/1/dataSources/13', 'displayName' => 'API UK', 'input' => 'API', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'feedLabel' => 'UA']],
        ]);

        $this->assertSame('-', FileSourceMatch::forLanguage($sources, 'uk')['feed_label']);
        $this->assertSame([['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED']], FileSourceMatch::forLanguage($sources, 'uk')['destinations']);
        $this->assertSame('LANG_RU', FileSourceMatch::forLanguage($sources, 'ru')['feed_label']);
        $this->assertNull(FileSourceMatch::forLanguage($sources, 'en'));
    }

    /** Два файлові джерела однієї мови — мітку не вгадуємо. */
    public function testAmbiguousFileSourcesGiveNoSuggestion(): void
    {
        $sources = $this->cachedSources([
            ['name' => 'accounts/1/dataSources/10', 'input' => 'FILE', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'feedLabel' => '-']],
            ['name' => 'accounts/1/dataSources/11', 'input' => 'FILE', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'feedLabel' => 'UA']],
        ]);

        $this->assertNull(FileSourceMatch::forLanguage($sources, 'uk'));
    }

    public function testJunkDestinationsAreDropped(): void
    {
        $sources = $this->cachedSources([
            ['name' => 'accounts/1/dataSources/10', 'input' => 'FILE', 'primaryProductDataSource' => ['contentLanguage' => 'uk', 'destinations' => [
                ['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED'],
                ['destination' => 'evil<script>', 'state' => 'ENABLED'],
                ['destination' => 'FREE_LISTINGS', 'state' => 'MAYBE'],
                'junk',
            ]]],
        ]);

        $this->assertSame([['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED']], $sources[0]['destinations']);
    }

    public function testProductSourceIsReadOnlyFromValidNames(): void
    {
        $this->assertSame('accounts/1/dataSources/10', StatusPoller::sourceOf(['dataSource' => 'accounts/1/dataSources/10']));
        $this->assertSame('', StatusPoller::sourceOf(['dataSource' => '../../x']));
        $this->assertSame('', StatusPoller::sourceOf([]));
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function cachedSources(array $raw): array
    {
        $settings = new ModuleSettings($this->settingsStub());
        $settings->setSourcesCache($raw, new DateTimeImmutable('2026-11-10T12:00:00+02:00'));

        return $settings->sourcesCache()['sources'];
    }

    private function client(FakeGoogle $google): MerchantClient
    {
        $key = ServiceAccountKey::fromJson(TestKeys::json());

        return new MerchantClient(new GoogleAuth($key, $google, fn() => 1_800_000_000), $google, '123', fn() => null);
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
