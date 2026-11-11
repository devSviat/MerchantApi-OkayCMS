<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Sync\SyncPlanner;
use PHPUnit\Framework\TestCase;

/** «Деталі» журналу — слова людською мовою: кожна причина планувальника має підпис і підказку в усіх мовах адмінки. */
class RunDetailsLabelsTest extends TestCase
{
    public function testEveryPlannerReasonHasLabelAndHint(): void
    {
        foreach (['ua', 'en', 'ru'] as $file) {
            $lang = [];
            include dirname(__DIR__, 4) . "/Okay/Modules/Sviat/MerchantApi/Backend/lang/{$file}.php";
            foreach (array_unique(array_values(SyncPlanner::DISPLAY_GROUPS)) as $reason) {
                $this->assertNotEmpty($lang["sviat__merchant_api__count_{$reason}"] ?? null, "{$file}: count_{$reason}");
                $this->assertNotEmpty($lang["sviat__merchant_api__count_{$reason}_hint"] ?? null, "{$file}: count_{$reason}_hint");
            }
        }
    }

    /** «Пізніше» з різних причин — одним словом із сумою: у журналі має бути компактно. */
    public function testWaitingReasonsAreSummedIntoOneLabel(): void
    {
        $shown = SyncPlanner::displayCounts(['critical' => 11, 'critical_cooldown' => 1, 'cooldown' => 2, 'backoff' => 3, 'unchanged' => 7514]);

        $this->assertSame(['critical' => 11, 'waiting' => 6, 'unchanged' => 7514], $shown);
        $this->assertSame([], array_values(array_diff(SyncPlanner::COUNT_REASONS, array_keys(SyncPlanner::DISPLAY_GROUPS))));
    }

    /** Список причин — повний: інакше нова причина з'явилась би в журналі сирим кодом. */
    public function testReasonListCoversPlannerSource(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/MerchantApi/Sync/SyncPlanner.php');
        $match = substr($source, (int) strpos($source, '$reason = match (true) {'));
        $match = substr($match, 0, (int) strpos($match, '};'));
        preg_match_all("/=> '([a-z_]+)',?\n/", $match, $literal);
        $this->assertNotEmpty($literal[1], 'reason match block not found');
        $reasons = array_merge(['new', 'critical', 'changed', 'refresh', 'retry', 'gone'], array_diff($literal[1], ['new']));

        $this->assertSame([], array_values(array_diff($reasons, SyncPlanner::COUNT_REASONS)));
    }
}
