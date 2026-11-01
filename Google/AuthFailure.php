<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

use RuntimeException;

/** Код причини від Google (invalid_grant, unauthorized_client…) або власний; без токенів і ключа. */
final class AuthFailure extends RuntimeException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct("Google OAuth failed: {$reason}");
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
