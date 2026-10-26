<?php

namespace Okay\Modules\Sviat\MerchantApi\Entities;

use Okay\Core\Entity\Entity;

/** Товари з проблемами в Merchant Center станом на останнє опитування (StatusPoller замінює весь набір). */
class MerchantApiIssuesEntity extends Entity
{
    protected static $fields = [
        'id',
        'content_language',
        'feed_label',
        'offer_id',
        'severity',
        'issues',
        'polled_at',
    ];

    protected static $defaultOrderFields = ['severity ASC', 'offer_id ASC'];
    protected static $table = 'sviat__merchant_api__issues';
    protected static $tableAlias = 'mais';
}
