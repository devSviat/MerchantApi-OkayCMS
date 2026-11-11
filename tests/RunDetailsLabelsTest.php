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
            foreach (SyncPlanner::COUNT_REASONS as $reason) {
                $this->assertNotEmpty($lang["sviat__merchant_api__count_{$reason}"] ?? null, "{$file}: count_{$reason}");
                $this->assertNotEmpty($lang["sviat__merchant_api__count_{$reason}_hint"] ?? null, "{$file}: count_{$reason}_hint");
            }
        }
    }

    /** Список причин — повний: інакше нова причина з'явилась би в журналі сирим кодом. */
    public function testReasonListCoversPlannerSource(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/MerchantApi/Sync/SyncPlanner.php');
        preg_match_all("/=> '([a-z_]+)',?\n/", $source, $literal);
        $reasons = array_merge(['new', 'critical', 'changed', 'refresh', 'retry', 'gone'], array_diff($literal[1], ['new']));

        $this->assertSame([], array_values(array_diff($reasons, SyncPlanner::COUNT_REASONS)));
    }
}
