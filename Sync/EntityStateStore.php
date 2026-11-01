<?php

namespace Okay\Modules\Sviat\MerchantApi\Sync;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\MerchantApi\Entities\MerchantApiItemsEntity;

class EntityStateStore implements StateStore
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** @var array<int, array<string, int>> мова → ID товару → id рядка */
    private array $rowIds = [];

    public function __construct(private EntityFactory $entityFactory)
    {
    }

    public function load(int $langId): array
    {
        $states = [];
        $this->rowIds[$langId] = [];
        foreach ($this->entity()->noLimit()->find(['lang_id' => $langId]) as $row) {
            $offerId = (string) $row->offer_id;
            $this->rowIds[$langId][$offerId] = (int) $row->id;
            $states[$offerId] = new ItemState(
                $offerId,
                $row->full_hash !== null && $row->full_hash !== '' ? (string) $row->full_hash : null,
                $row->critical_hash !== null && $row->critical_hash !== '' ? (string) $row->critical_hash : null,
                self::date($row->sent_at),
                (int) $row->attempts,
                self::date($row->next_attempt_at),
                $row->last_error !== null && $row->last_error !== '' ? (string) $row->last_error : null,
            );
        }

        return $states;
    }

    public function markSent(int $langId, string $offerId, string $fullHash, string $criticalHash, DateTimeImmutable $at): void
    {
        $this->save($langId, $offerId, [
            'full_hash' => $fullHash,
            'critical_hash' => $criticalHash,
            'sent_at' => $at->format(self::FORMAT),
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
        ]);
    }

    public function markFailed(int $langId, string $offerId, string $error, int $attempts, DateTimeImmutable $nextAttemptAt): void
    {
        $this->save($langId, $offerId, [
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt->format(self::FORMAT),
            'last_error' => mb_substr($error, 0, 400),
        ]);
    }

    public function forget(int $langId, array $offerIds): void
    {
        $ids = [];
        foreach ($offerIds as $offerId) {
            if (isset($this->rowIds[$langId][$offerId])) {
                $ids[] = $this->rowIds[$langId][$offerId];
                unset($this->rowIds[$langId][$offerId]);
            }
        }
        if ($ids !== []) {
            $this->entity()->delete($ids);
        }
    }

    public function resetHashes(int $langId): void
    {
        $ids = $this->entity()->cols(['id'])->noLimit()->find(['lang_id' => $langId]);
        if ($ids !== []) {
            $this->entity()->update(array_map(intval(...), $ids), ['full_hash' => null, 'critical_hash' => null]);
        }
    }

    /** @param array<string, mixed> $fields */
    private function save(int $langId, string $offerId, array $fields): void
    {
        $rowId = $this->rowIds[$langId][$offerId] ?? null;
        if ($rowId !== null) {
            $this->entity()->update($rowId, $fields);
            return;
        }
        $this->rowIds[$langId][$offerId] = (int) $this->entity()->add(['lang_id' => $langId, 'offer_id' => $offerId] + $fields);
    }

    private function entity(): MerchantApiItemsEntity
    {
        return $this->entityFactory->get(MerchantApiItemsEntity::class);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(self::FORMAT, $value);

        return $date === false ? null : $date;
    }
}
