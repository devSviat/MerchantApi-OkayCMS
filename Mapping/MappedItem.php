<?php

namespace Okay\Modules\Sviat\MerchantApi\Mapping;

/** Готовий до Merchant API запис і те, що з фіда перенести не вдалося (показується в адмінці). */
final readonly class MappedItem
{
    /**
     * @param array<string, mixed> $productInput тіло productInputs.insert
     * @param list<string> $problems коди на кшталт `unknown_tag:g:shipping`, `bad_value:g:price`
     */
    public function __construct(public string $offerId, public array $productInput, public array $problems = [])
    {
    }
}
