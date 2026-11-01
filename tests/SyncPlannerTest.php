<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Modules\Sviat\MerchantApi\Mapping\MappedItem;
use Okay\Modules\Sviat\MerchantApi\Sync\ItemHasher;
use Okay\Modules\Sviat\MerchantApi\Sync\ItemState;
use Okay\Modules\Sviat\MerchantApi\Sync\Operation;
use Okay\Modules\Sviat\MerchantApi\Sync\SyncPlanner;
use PHPUnit\Framework\TestCase;

class SyncPlannerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-26T12:00:00+02:00');
    }

    public function testNewItemIsInserted(): void
    {
        $plan = new SyncPlanner(100)->plan(['1' => $this->item('1')], [], $this->now);

        $this->assertSame([[Operation::INSERT, Operation::NEW, '1']], $this->ops($plan->operations));
    }

    public function testUnchangedRecentItemIsSkipped(): void
    {
        $item = $this->item('1');
        $plan = new SyncPlanner(100)->plan(['1' => $item], ['1' => $this->sent($item, '-1 hour')], $this->now);

        $this->assertSame([], $plan->operations);
        $this->assertSame(['unchanged' => 1], $plan->counts);
    }

    public function testCriticalChangeWaitsAnHourAfterPreviousSend(): void
    {
        $old = $this->item('1');
        $new = $this->item('1', ['availability' => 'OUT_OF_STOCK']);

        $recent = new SyncPlanner(100)->plan(['1' => $new], ['1' => $this->sent($old, '-5 minutes')], $this->now);
        $this->assertSame([], $recent->operations);
        $this->assertSame(['critical_cooldown' => 1], $recent->counts);

        $later = new SyncPlanner(100)->plan(['1' => $new], ['1' => $this->sent($old, '-61 minutes')], $this->now);
        $this->assertSame([[Operation::INSERT, Operation::CRITICAL, '1']], $this->ops($later->operations));
    }

    public function testNonCriticalChangeWaitsForCooldown(): void
    {
        $old = $this->item('1');
        $new = $this->item('1', ['title' => 'Нова назва']);

        $recent = new SyncPlanner(100)->plan(['1' => $new], ['1' => $this->sent($old, '-2 hours')], $this->now);
        $this->assertSame([], $recent->operations);
        $this->assertSame(['cooldown' => 1], $recent->counts);

        $later = new SyncPlanner(100)->plan(['1' => $new], ['1' => $this->sent($old, '-13 hours')], $this->now);
        $this->assertSame([[Operation::INSERT, Operation::CHANGED, '1']], $this->ops($later->operations));
    }

    public function testOldUnchangedItemIsRefreshedBeforeGoogleExpiresIt(): void
    {
        $item = $this->item('1');

        $plan = new SyncPlanner(100)->plan(['1' => $item], ['1' => $this->sent($item, '-21 days')], $this->now);

        $this->assertSame([[Operation::INSERT, Operation::REFRESH, '1']], $this->ops($plan->operations));
    }

    public function testBackoffSkipsUntilNextAttempt(): void
    {
        $item = $this->item('1');
        $state = new ItemState('1', null, null, null, 2, $this->now->modify('+10 minutes'), 'HTTP_503');

        $plan = new SyncPlanner(100)->plan(['1' => $item], ['1' => $state], $this->now);

        $this->assertSame([], $plan->operations);
        $this->assertSame(['backoff' => 1], $plan->counts);
    }

    public function testGoneItemIsDeletedAndNeverSentOneIsNot(): void
    {
        $states = [
            '1' => $this->sent($this->item('1'), '-1 day'),
            '2' => new ItemState('2', null, null, null, 1, null, 'HTTP_400'),
        ];
        $built = [];
        foreach (range(3, 40) as $id) {
            $built[(string) $id] = $this->item((string) $id);
            $states[(string) $id] = $this->sent($built[(string) $id], '-1 hour');
        }

        $plan = new SyncPlanner(100)->plan($built, $states, $this->now);

        $this->assertSame([[Operation::DELETE, Operation::GONE, '1']], $this->ops($plan->operations));
        $this->assertNull($plan->blockedDeletes);
    }

    public function testMassDeleteIsBlockedUntilThatExactCountIsConfirmed(): void
    {
        $states = [];
        $built = [];
        foreach (range(1, 300) as $id) {
            $states[(string) $id] = $this->sent($this->item((string) $id), '-1 hour');
            if ($id <= 250) {
                $built[(string) $id] = $this->item((string) $id);
            }
        }

        $blocked = new SyncPlanner(1000)->plan($built, $states, $this->now);
        $this->assertSame(50, $blocked->blockedDeletes);
        $this->assertSame([], $blocked->operations);

        $this->assertSame(array_map('strval', range(251, 300)), $blocked->gone);

        $otherSet = new SyncPlanner(1000)->plan($built, $states, $this->now, array_map('strval', range(250, 298)));
        $this->assertSame(50, $otherSet->blockedDeletes);

        $confirmed = new SyncPlanner(1000)->plan($built, $states, $this->now, $blocked->gone);
        $this->assertNull($confirmed->blockedDeletes);
        $this->assertCount(50, $confirmed->operations);

        // Порція вже видалена — решта як і раніше в межах підтвердженого набору.
        $rest = array_diff_key($states, array_flip(array_map('strval', range(251, 280))));
        $later = new SyncPlanner(1000)->plan($built, $rest, $this->now, $blocked->gone);
        $this->assertNull($later->blockedDeletes);
        $this->assertCount(20, $later->operations);
    }

    public function testSentItemThatBecameInvalidIsDeleted(): void
    {
        $states = [];
        $built = [];
        foreach (range(1, 30) as $id) {
            $states[(string) $id] = $this->sent($this->item((string) $id), '-1 hour');
            $built[(string) $id] = $this->item((string) $id);
        }
        $built['5'] = new MappedItem('5', $this->item('5')->productInput, ['bad_value:g:price']);

        $plan = new SyncPlanner(100)->plan($built, $states, $this->now);

        $this->assertSame([[Operation::DELETE, Operation::GONE, '5']], $this->ops($plan->operations));
        $this->assertSame(['bad_value:g:price'], $plan->invalid['5']);
    }

    public function testPurgeDeletesEverythingSentWithoutConfirmation(): void
    {
        $states = ['1' => $this->sent($this->item('1'), '-1 hour'), '2' => $this->sent($this->item('2'), '-1 hour')];

        $plan = new SyncPlanner(100)->plan(['1' => $this->item('1')], $states, $this->now, [], true);

        $this->assertSame([[Operation::DELETE, Operation::GONE, '1'], [Operation::DELETE, Operation::GONE, '2']], $this->ops($plan->operations));
        $this->assertNull($plan->blockedDeletes);
    }

    public function testBudgetKeepsShareForDeletesAndRefreshes(): void
    {
        $built = [];
        $states = [];
        foreach (range(1, 20) as $id) {
            $built["n{$id}"] = $this->item("n{$id}");
        }
        foreach (range(1, 5) as $id) {
            $states["r{$id}"] = $this->sent($built["r{$id}"] = $this->item("r{$id}"), '-25 days');
        }

        $plan = new SyncPlanner(10)->plan($built, $states, $this->now);

        $reasons = array_count_values(array_map(fn(Operation $o) => $o->reason, $plan->operations));
        $this->assertSame([Operation::NEW => 8, Operation::REFRESH => 2], $reasons);
        $this->assertSame(15, $plan->deferred);
    }

    public function testEmptyBuildNeverDeletesWithoutConfirmation(): void
    {
        $states = ['1' => $this->sent($this->item('1'), '-1 hour')];

        $plan = new SyncPlanner(100)->plan([], $states, $this->now);

        $this->assertSame(1, $plan->blockedDeletes);
        $this->assertSame([], $plan->operations);
    }

    public function testBrokenValueBlocksSendingUnknownTagDoesNot(): void
    {
        $broken = new MappedItem('1', $this->item('1')->productInput, ['bad_value:g:excluded_destination']);
        $unknown = new MappedItem('2', $this->item('2')->productInput, ['unknown_tag:g:shipping']);
        $input = $this->item('3')->productInput;
        unset($input['productAttributes']['price']);
        $noPrice = new MappedItem('3', $input);

        $plan = new SyncPlanner(100)->plan(['1' => $broken, '2' => $unknown, '3' => $noPrice], [], $this->now);

        $this->assertSame([[Operation::INSERT, Operation::NEW, '2']], $this->ops($plan->operations));
        $this->assertSame(['bad_value:g:excluded_destination'], $plan->invalid['1']);
        $this->assertSame(['missing:price'], $plan->invalid['3']);
    }

    public function testBudgetKeepsPriorityOrderAndDefersRest(): void
    {
        $refreshItem = $this->item('r');
        $changedOld = $this->item('c');
        $states = [
            'r' => $this->sent($refreshItem, '-25 days'),
            'c' => $this->sent($changedOld, '-1 day'),
            'g' => $this->sent($this->item('g'), '-1 day'),
        ];
        $built = [
            'r' => $refreshItem,
            'c' => $this->item('c', ['title' => 'інша']),
            'n' => $this->item('n'),
        ];

        $all = new SyncPlanner(100)->plan($built, $states, $this->now);
        $this->assertSame(['n', 'c', 'g', 'r'], array_map(fn(Operation $o) => $o->offerId, $all->operations));

        $tight = new SyncPlanner(2)->plan($built, $states, $this->now);
        $this->assertSame(['n', 'c'], array_map(fn(Operation $o) => $o->offerId, $tight->operations));
        $this->assertSame(2, $tight->deferred);
    }

    public function testHashIgnoresKeyOrderButNotListOrder(): void
    {
        $a = ['productAttributes' => ['title' => 'x', 'price' => ['currencyCode' => 'UAH', 'amountMicros' => '1']]];
        $b = ['productAttributes' => ['price' => ['amountMicros' => '1', 'currencyCode' => 'UAH'], 'title' => 'x']];
        $this->assertSame(ItemHasher::full($a), ItemHasher::full($b));

        $c = ['productAttributes' => ['additionalImageLinks' => ['1', '2']]];
        $d = ['productAttributes' => ['additionalImageLinks' => ['2', '1']]];
        $this->assertNotSame(ItemHasher::full($c), ItemHasher::full($d));
    }

    /** @param array<string, mixed> $override */
    private function item(string $id, array $override = []): MappedItem
    {
        return new MappedItem($id, [
            'offerId' => $id,
            'contentLanguage' => 'uk',
            'feedLabel' => 'UA',
            'productAttributes' => array_merge([
                'title' => "Товар {$id}",
                'link' => "https://shop.example/p/{$id}",
                'imageLink' => "https://shop.example/i/{$id}.jpg",
                'price' => ['amountMicros' => '100000000', 'currencyCode' => 'UAH'],
                'availability' => 'IN_STOCK',
            ], $override),
        ]);
    }

    private function sent(MappedItem $item, string $ago): ItemState
    {
        return new ItemState(
            $item->offerId,
            ItemHasher::full($item->productInput),
            ItemHasher::critical($item->productInput),
            $this->now->modify($ago),
        );
    }

    /**
     * @param list<Operation> $operations
     * @return list<array{string, string, string}>
     */
    private function ops(array $operations): array
    {
        return array_map(fn(Operation $o) => [$o->type, $o->reason, $o->offerId], $operations);
    }
}
