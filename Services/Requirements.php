<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Modules\OkayCMS\GoogleMerchant\Helpers\GoogleMerchantHelper;

/** Що потрібно модулю від сервера й OkayCMS; без цього синхронізація не вмикається. */
class Requirements
{
    /** @return list<string> ключі перекладів того, чого бракує */
    public function missing(): array
    {
        $missing = [];
        foreach (['curl', 'openssl', 'json'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = "sviat__merchant_api__req_ext_{$extension}";
            }
        }
        if (!class_exists(GoogleMerchantHelper::class)) {
            $missing[] = 'sviat__merchant_api__req_google_merchant';
        }

        return $missing;
    }
}
