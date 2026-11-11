<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

interface ItemSource
{
    /** @return array<string, array<int|string, mixed>> ID варіанта → запис фіда */
    public function build(int $langId, string $siteUrl): array;

    /** @return array<string, int> скільки записів останньої збірки відкинуто і чому (не надсилати, мінімальна ціна) */
    public function lastSkipped(): array;
}
