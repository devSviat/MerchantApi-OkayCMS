<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiRunsEntity;

class EntityRunLog implements RunLog
{
    public function __construct(private EntityFactory $entityFactory)
    {
    }

    public function record(array $run): void
    {
        $this->entityFactory->get(MerchantApiRunsEntity::class)->add($run);
    }
}
