<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

interface RunLog
{
    /**
     * Рядок запуску зі статусом «running» ще до роботи: запуск, який планувальник обірвав за таймаутом,
     * інакше не лишав би в журналі нічого.
     *
     * @param array<string, mixed> $run поля MerchantApiRunsEntity
     * @return int|null id рядка; null — якщо записати не вдалося, тоді finish() додасть новий
     */
    public function start(array $run): ?int;

    /** @param array<string, mixed> $run поля MerchantApiRunsEntity */
    public function finish(?int $id, array $run): void;

    /** Коли почався останній запуск мови (будь-який: успішний, обірваний, сухий). */
    public function lastStartedAt(int $langId): ?\DateTimeImmutable;
}
