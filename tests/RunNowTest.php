<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Backend\Controllers\MerchantApiAdmin;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Sync\SyncRunner;
use PHPUnit\Framework\TestCase;

/**
 * «Запустити зараз»: задача мови перевіряється щохвилини, а працює раз на 15 хвилин або одразу після запиту.
 * Запит — один час; його виконує будь-який запуск, що почався після нього, тож скидати нічого не треба.
 */
class RunNowTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function testFirstRunIsDue(): void
    {
        $this->assertTrue(SyncRunner::isDue(null, null, $this->at('12:00:05')));
    }

    /** Запуск стартує на кілька секунд після початку хвилини: без допуску розклад з'їжджав би на хвилину щоразу. */
    public function testRegularCadenceIsFifteenMinutesWithSecondsTolerance(): void
    {
        $last = $this->at('12:00:03');

        $this->assertFalse(SyncRunner::isDue($last, null, $this->at('12:14:01')));
        $this->assertTrue(SyncRunner::isDue($last, null, $this->at('12:15:01')));
        $this->assertTrue(SyncRunner::isDue($last, null, $this->at('12:40:00')));
    }

    public function testRequestAfterLastStartMakesItDueOnlyOnce(): void
    {
        $last = $this->at('12:00:03');

        $this->assertTrue(SyncRunner::isDue($last, $this->at('12:04:30'), $this->at('12:05:01')));
        $this->assertFalse(SyncRunner::isDue($this->at('12:05:01'), $this->at('12:04:30'), $this->at('12:06:01')));
    }

    /** «Очікує запуску», доки хоч одна мова не почала запуск після запиту. */
    public function testRequestIsPendingUntilEveryLanguageStartedAfterIt(): void
    {
        $requested = $this->at('12:04:30');

        $this->assertFalse(SyncRunner::requestPending(null, [1 => $this->at('12:00:03')]));
        $this->assertTrue(SyncRunner::requestPending($requested, [1 => $this->at('12:05:01'), 3 => $this->at('12:00:03')]));
        $this->assertTrue(SyncRunner::requestPending($requested, [1 => null]));
        $this->assertFalse(SyncRunner::requestPending($requested, [1 => $this->at('12:05:01'), 3 => $this->at('12:05:02')]));
    }

    public function testRequestIsStoredAsTime(): void
    {
        $settings = new ModuleSettings($this->settingsStub());
        $this->assertNull($settings->runRequestedAt());

        $settings->requestRun($this->at('12:04:30'));

        $this->assertEquals($this->at('12:04:30'), $settings->runRequestedAt());
        $this->values['sviat__merchant_api__run_requested_at'] = 'not a date';
        $this->assertNull($settings->runRequestedAt());
    }

    /** Вибір зі списку: довільне число в полі — це або зайві хвилини запуску, або забутий після переходу каталогу ліміт. */
    public function testOpsPerRunComesOnlyFromChoices(): void
    {
        $this->assertSame(500, MerchantApiAdmin::opsFromForm('500'));
        $this->assertSame(2000, MerchantApiAdmin::opsFromForm('2000'));
        $this->assertNull(MerchantApiAdmin::opsFromForm('1500'));
        $this->assertNull(MerchantApiAdmin::opsFromForm('abc'));
        $this->assertContains(ModuleSettings::DEFAULT_OPS_PER_RUN, ModuleSettings::OPS_CHOICES);
    }

    public function testRunNowMessageGoesToSyncSection(): void
    {
        $this->assertSame('sync', MerchantApiAdmin::sectionOf('run_now'));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-11-11 {$time}");
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
