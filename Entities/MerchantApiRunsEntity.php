<?php

namespace Okay\Modules\Sviat\MerchantApi\Entities;

use Okay\Core\Entity\Entity;

/** Журнал запусків синхронізації: по рядку на запуск задачі однієї мови. */
class MerchantApiRunsEntity extends Entity
{
    protected static $fields = [
        'id',
        'lang_id',
        'started_at',
        'finished_at',
        'status',
        'dry_run',
        'built',
        'sent',
        'deleted',
        'failed',
        'deferred',
        'invalid',
        'blocked_deletes',
        'summary',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__merchant_api__runs';
    protected static $tableAlias = 'mar';

    /** Запуски, початі раніше за вказаний момент ('Y-m-d H:i:s'). */
    protected function filter__started_before($value): void
    {
        $this->select->where('mar.started_at < :mar_started_before')->bindValue('mar_started_before', (string) $value);
    }
}
