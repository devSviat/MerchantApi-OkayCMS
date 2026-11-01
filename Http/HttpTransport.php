<?php

namespace Okay\Modules\Sviat\MerchantApi\Http;

interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @throws TransportFailure мережа, TLS, таймаут або заборонена адреса
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
