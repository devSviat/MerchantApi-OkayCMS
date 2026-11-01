<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

final readonly class Plan
{
    /**
     * @param list<Operation> $operations у порядку виконання, вже обрізані бюджетом
     * @param array<string, int> $counts причина → скільки (до обрізання бюджетом)
     * @param int $deferred скільки потрібних операцій не влізло в бюджет запуску
     * @param int|null $blockedDeletes видалення, зупинені запобіжником (null — запобіжник не спрацював)
     * @param array<string, list<string>> $invalid ID товару → чому не надсилається
     * @param list<string> $gone усі товари на видалення (і зупинені запобіжником — їх людина й підтверджує)
     */
    public function __construct(
        public array $operations,
        public array $counts,
        public int $deferred,
        public ?int $blockedDeletes,
        public array $invalid,
        public array $gone = [],
    ) {
    }
}
