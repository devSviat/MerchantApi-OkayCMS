<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

/**
 * Журнал запусків для показу: порожні запуски однієї мови поспіль зливаються в один рядок «×N».
 * Лише показ — у базі кожен запуск лишається окремим рядком: від часу останнього запуску залежить розклад.
 */
final class RunHistory
{
    private const array IDLE_STATUSES = ['ok', 'dry'];
    // Удвічі більше за розклад (15 хв): довша пауза між запусками — пропущені запуски, і група їх не ховає.
    private const int MAX_GAP_SECONDS = 1800;

    /**
     * Мови запускаються впереміш, тож серія ведеться окремо для кожної мови. Зливаються сусідні запуски мови з
     * однаковими числами й «Деталями», у яких у Google нічого не надіслано й не видалено (так само повторюється
     * й сухий прогін); запуск зі змінами, помилкою чи іншими числами обриває серію своєї мови.
     *
     * @param list<object> $runs рядки журналу від нових до старих, із summary_data
     * @return list<object> ті самі об'єкти з repeat (скільки запусків) і first_started_at (найраніший у групі)
     */
    public static function collapse(array $runs, int $limit): array
    {
        $rows = [];
        $open = [];
        foreach ($runs as $run) {
            $lang = (int) $run->lang_id;
            $key = self::idleKey($run);
            $group = isset($open[$lang]) ? $rows[$open[$lang]['row']] : null;
            if ($key !== null && $group !== null && $open[$lang]['key'] === $key
                && strtotime((string) $group->first_started_at) - strtotime((string) $run->started_at) <= self::MAX_GAP_SECONDS) {
                $group->repeat++;
                $group->first_started_at = $run->started_at;
                continue;
            }
            $run->repeat = 1;
            $run->first_started_at = $run->started_at;
            $rows[] = $run;
            if ($key === null) {
                unset($open[$lang]);
            } else {
                $open[$lang] = ['key' => $key, 'row' => array_key_last($rows)];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /** Відбиток запуску, що нічого не змінив у Google; null — запуск, який завжди показується окремо. */
    private static function idleKey(object $run): ?string
    {
        if (!in_array($run->status, self::IDLE_STATUSES, true)) {
            return null;
        }
        foreach (['sent', 'deleted', 'failed'] as $field) {
            if ((int) ($run->{$field} ?? 0) !== 0) {
                return null;
            }
        }
        if (($run->blocked_deletes ?? null) !== null && (int) $run->blocked_deletes !== 0) {
            return null;
        }
        $summary = is_array($run->summary_data ?? null) ? $run->summary_data : [];
        if (!empty($summary['error']) || !empty($summary['stopped'])) {
            return null;
        }

        return json_encode([$run->status, (int) $run->built, (int) ($run->deferred ?? 0), (int) ($run->invalid ?? 0), $summary['counts'] ?? null]);
    }
}
