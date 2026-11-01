<?php

namespace Okay\Modules\Sviat\MerchantApi\Entities;

use Okay\Core\Entity\Entity;

/** Що модуль надіслав у Google: товар (ID варіанта) однієї мови, відбитки останнього успішного надсилання. */
class MerchantApiItemsEntity extends Entity
{
    protected static $fields = [
        'id',
        'lang_id',
        'offer_id',
        'full_hash',
        'critical_hash',
        'sent_at',
        'attempts',
        'next_attempt_at',
        'last_error',
    ];

    protected static $defaultOrderFields = ['id ASC'];

    /** Товари, останнє надсилання яких Google відхилив (чекають повтору). */
    protected function filter__failed($value): void
    {
        if ($value) {
            $this->select->where('mai.attempts > 0');
        }
    }

    protected function filter__sent($value): void
    {
        if ($value) {
            $this->select->where('mai.sent_at IS NOT NULL');
        }
    }
    protected static $table = 'sviat__merchant_api__items';
    protected static $tableAlias = 'mai';
}
