<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Services\RunHistory;
use PHPUnit\Framework\TestCase;

/**
 * Журнал запусків: однакові порожні запуски однієї мови поспіль — одним рядком «×N» з діапазоном часу.
 * Дані в базі не змінюються, групування лише для показу.
 */
class RunHistoryTest extends TestCase
{
    public function testIdleRunsOfOneLanguageCollapseAcrossInterleavedLanguages(): void
    {
        $rows = RunHistory::collapse([
            $this->syncRun(1, '09:30'), $this->syncRun(3, '09:30'),
            $this->syncRun(1, '09:15'), $this->syncRun(3, '09:15'),
            $this->syncRun(1, '09:00'), $this->syncRun(3, '09:00'),
        ], 20);

        $this->assertSame([[1, '09:30', '09:00', 3], [3, '09:30', '09:00', 3]], $this->shape($rows));
    }

    /** Запуск, де щось надіслано, — окремий рядок і розриває серію: зверху й знизу від нього — різні групи. */
    public function testRunWithChangesBreaksTheSeries(): void
    {
        $rows = RunHistory::collapse([
            $this->syncRun(1, '09:30'),
            $this->syncRun(1, '09:15'),
            $this->syncRun(1, '09:00', sent: 11, counts: ['critical' => 11, 'unchanged' => 1189]),
            $this->syncRun(1, '08:45'),
            $this->syncRun(1, '08:30'),
        ], 20);

        $this->assertSame([[1, '09:30', '09:15', 2], [1, '09:00', '09:00', 1], [1, '08:45', '08:30', 2]], $this->shape($rows));
    }

    public function testFailuresAndDifferentTotalsNeverMerge(): void
    {
        $rows = RunHistory::collapse([
            $this->syncRun(1, '09:30'),
            $this->syncRun(1, '09:15', status: 'failed'),
            $this->syncRun(1, '09:00', status: 'failed'),
            $this->syncRun(1, '08:45', built: 1201, counts: ['unchanged' => 1201]),
            $this->syncRun(1, '08:30', status: 'dry'),
            $this->syncRun(1, '08:15', deferred: 5),
            $this->syncRun(1, '08:00', status: 'running'),
        ], 20);

        $this->assertSame(array_fill(0, 7, 1), array_column($this->shape($rows), 3));
    }

    /** Сухий прогін повторює той самий план (усе «відкладено»): однакові поспіль — теж один рядок. */
    public function testIdenticalDryRunsCollapse(): void
    {
        $dry = ['new' => 1210];
        $rows = RunHistory::collapse([
            $this->syncRun(1, '16:45', status: 'dry', built: 1210, deferred: 1010, counts: $dry),
            $this->syncRun(1, '16:30', status: 'dry', built: 1210, deferred: 1010, counts: $dry),
            $this->syncRun(1, '16:15', status: 'dry', built: 1210, deferred: 1000, counts: $dry),
        ], 20);

        $this->assertSame([[1, '16:45', '16:30', 2], [1, '16:15', '16:15', 1]], $this->shape($rows));
    }

    /** Пропущені запуски (сервер лежав, модуль був на паузі) не ховаються в групі: розрив понад 30 хвилин рве серію. */
    public function testGapLongerThanTwoIntervalsBreaksTheSeries(): void
    {
        $rows = RunHistory::collapse([
            $this->syncRun(1, '09:30'),
            $this->syncRun(1, '09:15'),
            $this->syncRun(1, '08:30'),
            $this->syncRun(1, '08:15'),
        ], 20);

        $this->assertSame([[1, '09:30', '09:15', 2], [1, '08:30', '08:15', 2]], $this->shape($rows));
    }

    /** Показується не більше заданої кількості рядків; читається більше, щоб рядків вистачило на довшу історію. */
    public function testLimitAppliesToShownRows(): void
    {
        $runs = [];
        foreach (range(0, 39) as $i) {
            $runs[] = $this->syncRun(1, sprintf('%02d:%02d', 9 - intdiv($i, 4), 45 - ($i % 4) * 15), sent: $i % 2);
        }

        $this->assertCount(20, RunHistory::collapse($runs, 20));
    }

    /** @return list<array{int, string, string, int}> мова, перший і останній час групи, скільки запусків */
    private function shape(array $rows): array
    {
        return array_map(static fn(object $r) => [(int) $r->lang_id, substr((string) $r->started_at, 11, 5), substr((string) $r->first_started_at, 11, 5), $r->repeat], $rows);
    }

    /** @param array<string, int>|null $counts */
    private function syncRun(int $lang, string $time, string $status = 'ok', int $built = 1200, int $sent = 0, int $deferred = 0, ?array $counts = null): object
    {
        $counts ??= $sent > 0 ? ['critical' => $sent, 'unchanged' => $built - $sent] : ['unchanged' => $built];

        return (object) [
            'lang_id' => $lang, 'started_at' => "2026-11-13 {$time}:01", 'status' => $status, 'built' => $built,
            'sent' => $sent, 'deleted' => 0, 'failed' => 0, 'invalid' => 0, 'deferred' => $deferred, 'blocked_deletes' => null,
            'summary_data' => ['counts' => $counts],
        ];
    }
}
