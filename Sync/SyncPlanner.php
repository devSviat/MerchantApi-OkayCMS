<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use DateInterval;
use DateTimeImmutable;
use Okay\Modules\Sviat\MerchantApi\Mapping\MappedItem;

/**
 * Що надіслати в Google цього запуску. Чиста функція: зібрані товари + стан → впорядкований план.
 *
 * Порядок: нові й критичні зміни → решта змін → видалення → освіження. Частина бюджету тримається за
 * видаленнями й освіженням, щоб товари, які змінюються щозапуску, не з'їдали все.
 */
final readonly class SyncPlanner
{
    /**
     * Без них Google товар прийме, але відхилить — а для вже надісланого це означало б,
     * що в Google лишилась би стара ціна й наявність. Такий запис не шлемо: свіжий не створюємо,
     * старий видаляємо.
     */
    private const array REQUIRED = ['title', 'link', 'imageLink', 'price', 'availability'];

    private const array PRIORITY = [
        Operation::NEW => 0,
        Operation::CRITICAL => 0,
        Operation::RETRY => 1,
        Operation::CHANGED => 1,
        Operation::GONE => 2,
        Operation::REFRESH => 3,
    ];

    /**
     * @param int $budget операцій за запуск
     * @param string $changedCooldown некритична зміна одного товару — не частіше (Google: «до двох разів на добу»)
     * @param string $criticalCooldown критична — не частіше: поле, що змінюється щозапуску, інакше палило б квоту
     * @param string $refreshAfter незмінний товар освіжається, поки Google не прибрав його за 30 днів
     * @param float $reservedShare частка бюджету для видалень і освіження, коли вони є
     * @param float $deleteGuardRatio частка відомих товарів, понад яку видалення чекають підтвердження
     * @param int $deleteGuardMin скільки видалень проходить без підтвердження, якщо збірка не порожня
     */
    public function __construct(
        private int $budget,
        private string $changedCooldown = 'PT12H',
        private string $criticalCooldown = 'PT1H',
        private string $refreshAfter = 'P20D',
        private float $reservedShare = 0.2,
        private float $deleteGuardRatio = 0.10,
        private int $deleteGuardMin = 20,
    ) {
    }

    /**
     * @param array<string, MappedItem> $built ключ — offerId запису
     * @param array<string, ItemState> $states
     * @param list<string> $confirmedDeletes ID, видалення яких людина підтвердила
     * @param bool $purge мову вимкнено: видалити з Google усе надіслане, нічого не вставляти
     */
    public function plan(array $built, array $states, DateTimeImmutable $now, array $confirmedDeletes = [], bool $purge = false): Plan
    {
        $operations = [];
        $counts = [];
        $invalid = [];
        $cooldownStart = $now->sub(new DateInterval($this->changedCooldown));
        $criticalStart = $now->sub(new DateInterval($this->criticalCooldown));
        $refreshStart = $now->sub(new DateInterval($this->refreshAfter));

        foreach ($purge ? [] : $built as $offerId => $item) {
            $offerId = (string) $offerId;
            $reasons = self::blockingProblems($item);
            if ($reasons !== []) {
                $invalid[$offerId] = $reasons;
                continue;
            }

            $state = $states[$offerId] ?? null;
            if ($state?->nextAttemptAt !== null && $state->nextAttemptAt > $now) {
                $counts['backoff'] = ($counts['backoff'] ?? 0) + 1;
                continue;
            }

            $full = ItemHasher::full($item->productInput);
            $critical = ItemHasher::critical($item->productInput);
            $recent = $state?->sentAt !== null && $state->sentAt > $criticalStart;
            $reason = match (true) {
                $state === null || $state->fullHash === null => Operation::NEW,
                $state->criticalHash !== $critical && !$recent => Operation::CRITICAL,
                $state->criticalHash !== $critical => 'critical_cooldown',
                $state->fullHash !== $full && ($state->sentAt === null || $state->sentAt <= $cooldownStart) => $state->attempts > 0 ? Operation::RETRY : Operation::CHANGED,
                $state->fullHash !== $full => 'cooldown',
                $state->sentAt === null || $state->sentAt <= $refreshStart => Operation::REFRESH,
                default => 'unchanged',
            };
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            if (isset(self::PRIORITY[$reason])) {
                $operations[] = new Operation(Operation::INSERT, $reason, $offerId, $item->productInput, $full, $critical);
            }
        }

        // Зниклі зі збірки й надіслані, що стали невалідними: в Google вони інакше лишились би зі старими даними.
        $gone = [];
        $known = 0;
        foreach ($states as $offerId => $state) {
            if ($state->sentAt === null) {
                continue;
            }
            $known++;
            $offerId = (string) $offerId;
            if (!isset($built[$offerId]) || isset($invalid[$offerId]) || $purge) {
                $gone[] = $offerId;
            }
        }

        $blockedDeletes = null;
        if ($gone !== []) {
            $guard = max($this->deleteGuardMin, (int) floor($known * $this->deleteGuardRatio));
            $suspicious = !$purge && ($built === [] || count($gone) > $guard);
            if ($suspicious && array_diff($gone, $confirmedDeletes) !== []) {
                $blockedDeletes = count($gone);
            } else {
                foreach ($gone as $offerId) {
                    $operations[] = new Operation(Operation::DELETE, Operation::GONE, $offerId);
                }
                $counts[Operation::GONE] = count($gone);
            }
        }

        usort($operations, static fn(Operation $a, Operation $b) => self::PRIORITY[$a->reason] <=> self::PRIORITY[$b->reason]);
        $selected = $this->withinBudget($operations);

        return new Plan($selected, $counts, count($operations) - count($selected), $blockedDeletes, $invalid, $gone);
    }

    /**
     * @param list<Operation> $operations впорядковані за пріоритетом
     * @return list<Operation>
     */
    private function withinBudget(array $operations): array
    {
        if (count($operations) <= $this->budget) {
            return $operations;
        }
        $urgent = array_values(array_filter($operations, static fn(Operation $o) => self::PRIORITY[$o->reason] <= 1));
        $maintenance = array_values(array_filter($operations, static fn(Operation $o) => self::PRIORITY[$o->reason] > 1));
        $reserved = min(count($maintenance), (int) floor($this->budget * $this->reservedShare));
        $takeUrgent = min(count($urgent), $this->budget - $reserved);

        return array_merge(
            array_slice($urgent, 0, $takeUrgent),
            array_slice($maintenance, 0, $this->budget - $takeUrgent),
        );
    }

    /**
     * Невідомий тег не блокує (решта даних точна, проблема видна в адмінці). Зіпсоване значення блокує:
     * без нього товар пішов би без, наприклад, виключення з реклами.
     *
     * @return list<string>
     */
    private static function blockingProblems(MappedItem $item): array
    {
        $blocking = array_values(array_filter($item->problems, static fn(string $p) => !str_starts_with($p, 'unknown_tag:') && !str_starts_with($p, 'unsupported:')));
        $attributes = $item->productInput['productAttributes'] ?? [];
        foreach (self::REQUIRED as $field) {
            if (!isset($attributes[$field]) || $attributes[$field] === '') {
                $blocking[] = "missing:{$field}";
            }
        }

        return $blocking;
    }
}
