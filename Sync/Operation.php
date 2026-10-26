<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

final readonly class Operation
{
    public const string INSERT = 'insert';
    public const string DELETE = 'delete';

    /** Причина — для журналу й адмінки. */
    public const string NEW = 'new';
    public const string CRITICAL = 'critical';
    public const string CHANGED = 'changed';
    public const string REFRESH = 'refresh';
    public const string RETRY = 'retry';
    public const string GONE = 'gone';

    /** @param array<string, mixed>|null $productInput */
    public function __construct(
        public string $type,
        public string $reason,
        public string $offerId,
        public ?array $productInput = null,
        public ?string $fullHash = null,
        public ?string $criticalHash = null,
    ) {
    }
}
