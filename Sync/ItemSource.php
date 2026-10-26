<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

interface ItemSource
{
    /** @return array<string, array<int|string, mixed>> ID варіанта → запис фіда */
    public function build(int $langId, int $feedId, string $siteUrl): array;
}
