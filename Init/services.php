<?php

namespace Okay\Modules\Sviat\MerchantApi;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Money;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\QueryFactory;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClientFactory;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogProducts;
use Okay\Modules\Sviat\MerchantApi\Services\CatalogStats;
use Okay\Modules\Sviat\MerchantApi\Services\CredentialStore;
use Okay\Modules\Sviat\MerchantApi\Services\FeedItemBuilder;
use Okay\Modules\Sviat\MerchantApi\Services\ModuleSettings;
use Okay\Modules\Sviat\MerchantApi\Services\Requirements;
use Okay\Modules\Sviat\MerchantApi\Http\CurlTransport;
use Okay\Modules\Sviat\MerchantApi\Sync\Clock;
use Okay\Modules\Sviat\MerchantApi\Sync\EntityRunLog;
use Okay\Modules\Sviat\MerchantApi\Sync\EntityStateStore;
use Okay\Modules\Sviat\MerchantApi\Sync\StatusPoller;
use Okay\Modules\Sviat\MerchantApi\Sync\SyncRunner;

$feedItemBuilder = [
    'class' => FeedItemBuilder::class,
    'arguments' => [
        new SR(ExtendedPdo::class),
        new SR(Database::class),
        new SR(QueryFactory::class),
        new SR(EntityFactory::class),
        new SR(Languages::class),
        new SR(Settings::class),
        new SR(Image::class),
        new SR(Money::class),
        new SR(Router::class),
    ],
];

return [
    CredentialStore::class => [
        'class' => CredentialStore::class,
        'arguments' => [new SR(Settings::class)],
    ],
    ModuleSettings::class => [
        'class' => ModuleSettings::class,
        'arguments' => [new SR(Settings::class)],
    ],
    Requirements::class => [
        'class' => Requirements::class,
    ],
    FeedItemBuilder::class => $feedItemBuilder,
    CurlTransport::class => [
        'class' => CurlTransport::class,
    ],
    MerchantClientFactory::class => [
        'class' => MerchantClientFactory::class,
        'arguments' => [new SR(CurlTransport::class)],
    ],
    Clock::class => [
        'class' => Clock::class,
    ],
    EntityStateStore::class => [
        'class' => EntityStateStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    EntityRunLog::class => [
        'class' => EntityRunLog::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    SyncRunner::class => [
        'class' => SyncRunner::class,
        'arguments' => [
            new SR(ModuleSettings::class),
            new SR(CredentialStore::class),
            new SR(Requirements::class),
            new SR(FeedItemBuilder::class),
            new SR(EntityStateStore::class),
            new SR(EntityRunLog::class),
            new SR(MerchantClientFactory::class),
            new SR(Clock::class),
        ],
    ],
    StatusPoller::class => [
        'class' => StatusPoller::class,
        'arguments' => [
            new SR(ModuleSettings::class),
            new SR(CredentialStore::class),
            new SR(MerchantClientFactory::class),
            new SR(EntityFactory::class),
            new SR(Clock::class),
        ],
    ],
    CatalogStats::class => [
        'class' => CatalogStats::class,
        'arguments' => [
            new SR(QueryFactory::class),
            new SR(EntityFactory::class),
        ],
    ],
    CatalogProducts::class => [
        'class' => CatalogProducts::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(ProductsHelper::class),
        ],
    ],
];
