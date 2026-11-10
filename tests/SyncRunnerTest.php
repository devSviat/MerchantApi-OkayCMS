<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Google\GoogleAuth;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClient;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClientFactory;
use Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use Okay\Modules\Sviat\MerchantApi\Services\CredentialStore;
use Okay\Modules\Sviat\MerchantApi\Services\LanguageTarget;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Services\Requirements;
use Okay\Modules\Sviat\MerchantApi\Sync\Clock;
use Okay\Modules\Sviat\MerchantApi\Sync\ItemSource;
use Okay\Modules\Sviat\MerchantApi\Sync\ItemState;
use Okay\Modules\Sviat\MerchantApi\Sync\RunLog;
use Okay\Modules\Sviat\MerchantApi\Sync\StateStore;
use Okay\Modules\Sviat\MerchantApi\Sync\SyncRunner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestKeys.php';
require_once __DIR__ . '/FakeGoogle.php';

class SyncRunnerTest extends TestCase
{
    private const int LANG = 3;
    private const string SOURCE = 'accounts/123/dataSources/456';
    private const string OTHER_SOURCE = 'accounts/123/dataSources/789';

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string|int, array<int|string, mixed>> */
    private array $feed = [];

    /** @var list<array<string, mixed>> */
    private array $runs = [];
    /** @var list<array<string, mixed>> */
    private array $started = [];

    private FakeGoogle $google;
    private DateTimeImmutable $now;
    private MemoryStateStore $store;
    private ?\Throwable $buildFails = null;

    protected function setUp(): void
    {
        $this->google = new FakeGoogle();
        $this->now = new DateTimeImmutable('2026-10-26T12:00:00+02:00');
        $this->store = new MemoryStateStore();
        $settings = $this->settings();
        $settings->setLanguages([LanguageTarget::create(self::LANG, 'uk', 'UA', 1, self::SOURCE)]);
        $settings->setSiteUrl('https://shop.example');
        $settings->setAccountId('123');
        $settings->setPaused(false);
        $settings->setDryRun(false);
        new CredentialStore($this->settingsStub())->saveJson(TestKeys::json(), $this->now);
        foreach (['1', '2', '3'] as $id) {
            $this->feed[$id] = $this->feedItem($id);
        }
    }

    public function testPausedOrUnselectedLanguageDoesNothing(): void
    {
        $this->settings()->setPaused(true);
        $this->runner()->runLanguage(self::LANG);
        $this->settings()->setPaused(false);
        $this->runner()->runLanguage(1);

        $this->assertSame([], $this->runs);
        $this->assertSame([], $this->google->requests);
    }

    public function testDryRunPlansButSendsNothing(): void
    {
        $this->settings()->setDryRun(true);
        $this->values[CredentialStore::KEY] = '';

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame([], $this->google->requests);
        $this->assertSame([], $this->store->rows);
        $this->assertSame('dry', $this->runs[0]['status']);
        $this->assertSame(3, $this->runs[0]['built']);
        $this->assertSame(['new' => 3], $this->summary(0)['counts']);
    }

    public function testFirstRunSendsAllSecondRunNothing(): void
    {
        $this->runner()->runLanguage(self::LANG);
        $this->assertCount(3, $this->google->products);
        $this->assertSame('ok', $this->runs[0]['status']);
        $this->assertSame(3, $this->runs[0]['sent']);

        $this->google->requests = [];
        $this->runner()->runLanguage(self::LANG);
        $this->assertSame([], $this->google->apiCalls());
        $this->assertSame(['unchanged' => 3], $this->summary(1)['counts']);
    }

    public function testBackorderExclusionsReachGoogle(): void
    {
        $this->feed['2']['g:availability'] = ['data' => 'backorder'];
        $this->feed['2']['g:availability_date'] = ['data' => '2026-11-16T12:00+0200'];
        $this->feed['2']['g:excluded_destination:Shopping_ads'] = ['tag' => 'g:excluded_destination', 'data' => 'Shopping_ads'];

        $this->runner()->runLanguage(self::LANG);

        $sent = $this->google->products[FakeGoogle::id('uk', 'UA', '2')]['productAttributes'];
        $this->assertSame('BACKORDER', $sent['availability']);
        $this->assertSame('2026-11-16T12:00:00+02:00', $sent['availabilityDate']);
        $this->assertSame(['SHOPPING_ADS'], $sent['excludedDestinations']);
    }

    public function testGoneProductIsDeletedInGoogle(): void
    {
        $this->runner()->runLanguage(self::LANG);
        unset($this->feed['3']);

        $this->runner()->runLanguage(self::LANG);

        $this->assertArrayNotHasKey(FakeGoogle::id('uk', 'UA', '3'), $this->google->products);
        $this->assertArrayNotHasKey('3', $this->store->states(self::LANG));
        $this->assertSame(1, $this->runs[1]['deleted']);
    }

    /** Рев'ю H2: інакше в Google лишилась би стара ціна й «в наявності». */
    public function testSentProductThatBecameInvalidIsRemovedFromGoogle(): void
    {
        $this->runner()->runLanguage(self::LANG);
        $this->feed['2']['g:price'] = ['data' => 'ціна'];

        $this->runner()->runLanguage(self::LANG);

        $this->assertArrayNotHasKey(FakeGoogle::id('uk', 'UA', '2'), $this->google->products);
        $this->assertSame(['bad_value:g:price', 'missing:price'], $this->summary(1)['invalid']['2']);
    }

    /** Рев'ю H1: те саме мова~мітка~ID в іншому джерелі — товари переносяться, а не лишаються в старому. */
    public function testDataSourceChangeResendsEverythingIntoNewSource(): void
    {
        $this->runner()->runLanguage(self::LANG);
        $this->settings()->setLanguages([LanguageTarget::create(self::LANG, 'uk', 'UA', 1, self::OTHER_SOURCE)]);

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame(array_fill_keys(array_keys($this->google->products), self::OTHER_SOURCE), $this->google->sourceOf);
        $this->assertCount(3, $this->google->products);
        $this->assertSame(self::OTHER_SOURCE, $this->settings()->activeTarget(self::LANG)->dataSource);
    }

    /** Рев'ю H1: зміна мітки при товарах у Google — стоп, а не дублі під новою міткою. */
    public function testLabelChangeWithProductsInGoogleStops(): void
    {
        $this->runner()->runLanguage(self::LANG);
        $this->settings()->setLanguages([LanguageTarget::create(self::LANG, 'uk', 'NEW', 1, self::SOURCE)]);
        $this->google->requests = [];

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame([], $this->google->apiCalls());
        $this->assertSame(['error' => 'target_changed'], $this->summary(1));
    }

    /** Рев'ю H3: вимкнена мова прибирає свої товари з Google, тоді й себе. */
    public function testRemovedLanguageDeletesItsProductsThenItself(): void
    {
        $this->runner()->runLanguage(self::LANG);
        $this->settings()->setLanguages([LanguageTarget::create(self::LANG, 'uk', 'UA', 1, self::SOURCE)->withRemoving(true)]);

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame([], $this->google->products);
        $this->assertSame([], $this->settings()->languages());
        $this->assertNull($this->settings()->activeTarget(self::LANG));
    }

    public function testOneRejectedProductDoesNotStopOthers(): void
    {
        $this->google->failures = [FakeGoogle::error(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', 'Invalid value')];

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame('partial', $this->runs[0]['status']);
        $this->assertSame(2, $this->runs[0]['sent']);
        $this->assertSame(1, $this->runs[0]['failed']);
        $failed = array_values(array_filter($this->store->states(self::LANG), fn(ItemState $s) => $s->attempts > 0))[0];
        $this->assertSame('400 INVALID_ARGUMENT: Invalid value', $failed->lastError);
        $this->assertEquals($this->now->modify('+15 minutes'), $failed->nextAttemptAt);
    }

    /** Рев'ю M2: тимчасова відмова — пауза цьому товару, а не вічна зупинка на ньому. */
    public function testTransientFailureBacksOffThatItemAndStopsOnlyAfterSeveral(): void
    {
        $this->google->failures = array_fill(0, 3, FakeGoogle::error(503, 'UNAVAILABLE', 'BACKEND_ERROR'));
        $this->runner()->runLanguage(self::LANG);
        $this->assertSame(['sent' => 2, 'failed' => 1], ['sent' => $this->runs[0]['sent'], 'failed' => $this->runs[0]['failed']]);

        $this->store->rows = [];
        $this->google->failures = array_fill(0, 9, FakeGoogle::error(503, 'UNAVAILABLE', 'BACKEND_ERROR'));
        $this->runner()->runLanguage(self::LANG);
        $this->assertSame('503:BACKEND_ERROR: fake', $this->summary(1)['stopped']);
    }

    /** Рев'ю M7: однакова відмова раз за разом — це налаштування, а не товари. */
    public function testRepeatedIdenticalRejectionStopsRun(): void
    {
        foreach (range(4, 12) as $id) {
            $this->feed[(string) $id] = $this->feedItem((string) $id);
        }
        $this->google->failures = array_fill(0, 12, FakeGoogle::error(400, 'INVALID_ARGUMENT', 'FEED_LABEL_MISMATCH'));

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame(5, $this->runs[0]['failed']);
        $this->assertSame('repeated:400:FEED_LABEL_MISMATCH: fake', $this->summary(0)['stopped']);
    }

    public function testInsertIntoMissingSourceStopsRun(): void
    {
        $this->google->failures = [FakeGoogle::error(404, 'NOT_FOUND', 'DATA_SOURCE_NOT_FOUND')];

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame('failed', $this->runs[0]['status']);
        $this->assertSame([], $this->store->states(self::LANG));
    }

    public function testQuotaStopsRunAndLeavesRestForNextRun(): void
    {
        $this->runner()->runLanguage(self::LANG);
        foreach (['1', '2', '3'] as $id) {
            $this->feed[$id]['g:availability'] = ['data' => 'out of stock'];
        }
        $this->now = $this->now->modify('+2 hours');
        $this->google->failures = [new HttpResponse(200, '{}'), FakeGoogle::error(429, 'RESOURCE_EXHAUSTED', 'QUOTA_REQUEST_RATE_TOO_HIGH')];

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame('partial', $this->runs[1]['status']);
        $this->assertSame(1, $this->runs[1]['sent']);
        $this->assertSame('429:QUOTA_REQUEST_RATE_TOO_HIGH: fake', $this->summary(1)['stopped']);
        $this->assertSame([], array_filter($this->store->states(self::LANG), fn(ItemState $s) => $s->attempts > 0), 'quota is not a product error');
    }

    /**
     * Рев'ю M4: підтверджений набір видаляється порціями без нового підтвердження.
     * Масштаб такий, що й після кожної порції решта вища за поріг запобіжника — інакше тест не бачить різниці.
     */
    public function testConfirmedMassDeleteRunsInBudgetSlicesWithOneConfirmation(): void
    {
        $this->feed = [];
        foreach (range(1, 300) as $id) {
            $this->feed[(string) $id] = $this->feedItem((string) $id);
        }
        $this->runner()->runLanguage(self::LANG);
        $this->feed = array_slice($this->feed, 0, 50, true);

        $this->runner()->runLanguage(self::LANG);
        $this->assertSame(250, $this->runs[1]['blocked_deletes']);
        $this->assertCount(300, $this->google->products);

        $this->settings()->setConfirmedDeletes(self::LANG, $this->settings()->pendingDeletes(self::LANG));
        $this->settings()->setOpsPerRun(100);
        foreach (range(1, 3) as $slice) {
            $this->runner()->runLanguage(self::LANG);
            $this->assertNull($this->runs[1 + $slice]['blocked_deletes'], "slice {$slice} needed a new confirmation");
        }

        $this->assertCount(50, $this->google->products);
        $this->runner()->runLanguage(self::LANG);
        $this->assertSame([], $this->settings()->confirmedDeletes(self::LANG));
    }

    public function testEmptyBuildNeverDeletesWithoutConfirmation(): void
    {
        $this->runner()->runLanguage(self::LANG);
        $this->feed = [];

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame(3, $this->runs[1]['blocked_deletes']);
        $this->assertCount(3, $this->google->products);
    }

    /** Рев'ю S4/L6: виняток посеред запуску — рядок у журналі, а не тиша. */
    public function testExceptionIsLoggedAsFailedRunWithoutDetails(): void
    {
        $this->buildFails = new \RuntimeException("SQL error near 'x'\nsecret line");

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame('failed', $this->runs[0]['status']);
        $this->assertSame("RuntimeException: SQL error near 'x' secret line", $this->summary(0)['stopped']);
    }

    /** Рев'ю S6: ключ — g:id запису, а не ключ масиву збирача. */
    public function testOfferIdComesFromRecordNotFromArrayKey(): void
    {
        $this->feed = ['999' => $this->feedItem('7'), '998' => $this->feedItem('7')];

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame([FakeGoogle::id('uk', 'UA', '7')], array_keys($this->google->products));
        $this->assertSame(['998' => ['bad_offer_id']], $this->summary(0)['invalid']);
    }

    /** Рядок журналу з'являється до роботи: запуск, обірваний планувальником, інакше не лишав би сліду. */
    public function testRunRowIsWrittenAsRunningBeforeWork(): void
    {
        $this->buildFails = new \RuntimeException('boom');

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame('running', $this->started[0]['status']);
        $this->assertCount(1, $this->runs);
        $this->assertSame('failed', $this->runs[0]['status']);
    }

    public function testLiveRunWithoutKeyFailsBeforeAnyCall(): void
    {
        $this->values[CredentialStore::KEY] = '';

        $this->runner()->runLanguage(self::LANG);

        $this->assertSame([], $this->google->requests);
        $this->assertSame('failed', $this->runs[0]['status']);
        $this->assertSame(['error' => 'key'], $this->summary(0));
    }

    /** @return array<string, mixed> */
    private function summary(int $run): array
    {
        return json_decode($this->runs[$run]['summary'], true);
    }

    private function runner(): SyncRunner
    {
        $source = new class ($this) implements ItemSource {
            public function __construct(private SyncRunnerTest $test)
            {
            }

            public function build(int $langId, int $feedId, string $siteUrl): array
            {
                return $this->test->feed();
            }
        };
        $log = new class ($this) implements RunLog {
            public function __construct(private SyncRunnerTest $test)
            {
            }

            public function start(array $run): ?int
            {
                return $this->test->startRun($run);
            }

            public function finish(?int $id, array $run): void
            {
                $this->test->finishRun($id, $run);
            }
        };
        $factory = new class ($this->google) extends MerchantClientFactory {
            public function __construct(private FakeGoogle $google)
            {
                parent::__construct($google);
            }

            public function create(ServiceAccountKey $key, string $accountId): MerchantClient
            {
                return new MerchantClient(new GoogleAuth($key, $this->google, fn() => 1_800_000_000), $this->google, $accountId, fn() => null);
            }
        };
        $clock = new class ($this) extends Clock {
            public function __construct(private SyncRunnerTest $test)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->test->now();
            }
        };
        $requirements = $this->createStub(Requirements::class);
        $requirements->method('missing')->willReturn([]);

        return new SyncRunner($this->settings(), new CredentialStore($this->settingsStub()), $requirements, $source, $this->store, $log, $factory, $clock);
    }

    /** @return array<string|int, array<int|string, mixed>> */
    public function feed(): array
    {
        if ($this->buildFails !== null) {
            throw $this->buildFails;
        }

        return $this->feed;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** @param array<string, mixed> $run */
    public function startRun(array $run): int
    {
        $this->started[] = $run;

        return count($this->started);
    }

    /** Завершення оновлює рядок, створений на старті цього ж запуску, а не додає новий. */
    public function finishRun(?int $id, array $run): void
    {
        $this->assertSame(count($this->started), $id, 'finish() must update the row start() created');
        $this->runs[] = $run;
    }

    /** @return array<int|string, mixed> */
    private function feedItem(string $id): array
    {
        return [
            'title' => ['data' => "Товар {$id}"],
            'link' => ['data' => "https://shop.example/p/{$id}"],
            'g:id' => ['data' => $id],
            'g:price' => ['data' => '100 UAH'],
            'g:availability' => ['data' => 'in stock'],
            ['tag' => 'g:image_link', 'data' => "https://shop.example/i/{$id}.jpg"],
        ];
    }

    private function settings(): ModuleSettings
    {
        return new ModuleSettings($this->settingsStub());
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

final class MemoryStateStore implements StateStore
{
    /** @var array<int, array<string, ItemState>> мова → ID → стан */
    public array $rows = [];

    /** @return array<string, ItemState> */
    public function states(int $langId): array
    {
        return $this->rows[$langId] ?? [];
    }

    public function load(int $langId): array
    {
        return $this->rows[$langId] ?? [];
    }

    public function markSent(int $langId, string $offerId, string $fullHash, string $criticalHash, DateTimeImmutable $at): void
    {
        $this->rows[$langId][$offerId] = new ItemState($offerId, $fullHash, $criticalHash, $at);
    }

    public function markFailed(int $langId, string $offerId, string $error, int $attempts, DateTimeImmutable $nextAttemptAt): void
    {
        $old = $this->rows[$langId][$offerId] ?? null;
        $this->rows[$langId][$offerId] = new ItemState($offerId, $old?->fullHash, $old?->criticalHash, $old?->sentAt, $attempts, $nextAttemptAt, $error);
    }

    public function forget(int $langId, array $offerIds): void
    {
        foreach ($offerIds as $offerId) {
            unset($this->rows[$langId][$offerId]);
        }
        if (($this->rows[$langId] ?? null) === []) {
            unset($this->rows[$langId]);
        }
    }

    public function resetHashes(int $langId): void
    {
        foreach ($this->rows[$langId] ?? [] as $offerId => $state) {
            $this->rows[$langId][$offerId] = new ItemState($offerId, null, null, $state->sentAt, $state->attempts, $state->nextAttemptAt, $state->lastError);
        }
    }
}
