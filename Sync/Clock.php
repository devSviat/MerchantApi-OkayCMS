<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use DateTimeImmutable;

/** Поточний час; у тестах — підмінений. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
