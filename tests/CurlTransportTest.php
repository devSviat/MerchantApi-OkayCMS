<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Http\CurlTransport;
use Okay\Modules\Sviat\MerchantApi\Http\TransportFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurlTransportTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function allowed(): array
    {
        return [
            'token' => ['https://oauth2.googleapis.com/token'],
            'product input' => ['https://merchantapi.googleapis.com/products/v1/accounts/123/productInputs:insert?dataSource=accounts/123/dataSources/456'],
            'encoded id' => ['https://merchantapi.googleapis.com/products/v1/accounts/123/productInputs/dWt-VUEtMQ'],
            'tilde id' => ['https://merchantapi.googleapis.com/products/v1/accounts/123/productInputs/uk~UA~1'],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllowsGoogleEndpoints(string $url): void
    {
        CurlTransport::assertAllowed($url);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function forbidden(): array
    {
        return [
            'http' => ['http://merchantapi.googleapis.com/products/v1/x'],
            'other host' => ['https://example.com/token'],
            'lookalike suffix' => ['https://merchantapi.googleapis.com.evil.example/x'],
            'lookalike prefix' => ['https://evilmerchantapi.googleapis.com/x'],
            'userinfo' => ['https://merchantapi.googleapis.com@evil.example/x'],
            'user only' => ['https://user@merchantapi.googleapis.com/x'],
            'port' => ['https://merchantapi.googleapis.com:8443/x'],
            'fragment trick' => ['https://evil.example#@merchantapi.googleapis.com/x'],
            'backslash trick' => ['https://merchantapi.googleapis.com\\@evil.example/x'],
            'whitespace' => ["https://merchantapi.googleapis.com/x\r\nHost: evil.example"],
            'no path' => ['https://merchantapi.googleapis.com'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('forbidden')]
    public function testRejectsEverythingElse(string $url): void
    {
        $this->expectException(TransportFailure::class);
        CurlTransport::assertAllowed($url);
    }

    public function testSendChecksUrlBeforeAnyNetwork(): void
    {
        $this->expectException(TransportFailure::class);
        new CurlTransport()->send('GET', 'https://127.0.0.1/');
    }
}
