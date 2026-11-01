<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use DateTimeImmutable;

/** Що модуль знає про один товар однієї мови в Google. */
final readonly class ItemState
{
    public function __construct(
        public string $offerId,
        public ?string $fullHash,
        public ?string $criticalHash,
        public ?DateTimeImmutable $sentAt,
        public int $attempts = 0,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?string $lastError = null,
    ) {
    }
}
