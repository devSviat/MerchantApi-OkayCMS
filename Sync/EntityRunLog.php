<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiRunsEntity;

class EntityRunLog implements RunLog
{
    public function __construct(private EntityFactory $entityFactory)
    {
    }

    public function start(array $run): ?int
    {
        $id = $this->entityFactory->get(MerchantApiRunsEntity::class)->add($run);

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    public function finish(?int $id, array $run): void
    {
        $entity = $this->entityFactory->get(MerchantApiRunsEntity::class);
        if ($id === null) {
            $entity->add($run);
            return;
        }
        $entity->update($id, $run);
    }

    public function lastStartedAt(int $langId): ?\DateTimeImmutable
    {
        $last = $this->entityFactory->get(MerchantApiRunsEntity::class)->cols(['started_at'])->find(['lang_id' => $langId, 'limit' => 1]);
        $startedAt = is_object($last[0] ?? null) ? (string) $last[0]->started_at : (string) ($last[0] ?? '');
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startedAt);

        return $at === false ? null : $at;
    }
}
