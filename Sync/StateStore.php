<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use DateTimeImmutable;

interface StateStore
{
    /** @return array<string, ItemState> ID товару → стан */
    public function load(int $langId): array;

    public function markSent(int $langId, string $offerId, string $fullHash, string $criticalHash, DateTimeImmutable $at): void;

    public function markFailed(int $langId, string $offerId, string $error, int $attempts, DateTimeImmutable $nextAttemptAt): void;

    /** @param list<string> $offerIds */
    public function forget(int $langId, array $offerIds): void;

    /** Усі товари мови — «ще не надіслані»: наступні запуски відправлять їх заново (в інше джерело). */
    public function resetHashes(int $langId): void;
}
