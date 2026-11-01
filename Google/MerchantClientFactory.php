<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

use Okay\Modules\Sviat\MerchantApi\Http\HttpTransport;

class MerchantClientFactory
{
    public function __construct(private HttpTransport $transport)
    {
    }

    public function create(ServiceAccountKey $key, string $accountId): MerchantClient
    {
        return new MerchantClient(
            new GoogleAuth($key, $this->transport, static fn(): int => time()),
            $this->transport,
            $accountId,
            static function (int $seconds): void {
                sleep($seconds);
            },
        );
    }
}
