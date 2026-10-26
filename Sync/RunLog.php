<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

interface RunLog
{
    /** @param array<string, mixed> $run поля MerchantApiRunsEntity */
    public function record(array $run): void;
}
