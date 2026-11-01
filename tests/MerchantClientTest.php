<?php

namespace Modules\Sviat\MerchantApi;

use InvalidArgumentException;
use Okay\Modules\Sviat\MerchantApi\Google\ApiError;
use Okay\Modules\Sviat\MerchantApi\Google\GoogleAuth;
use Okay\Modules\Sviat\MerchantApi\Google\MerchantClient;
use Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestKeys.php';
require_once __DIR__ . '/FakeGoogle.php';

class MerchantClientTest extends TestCase
{
    private FakeGoogle $google;

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->google = new FakeGoogle();
    }

    public function testInsertSendsJsonWithBearerToDataSource(): void
    {
        $this->client()->insertProductInput('accounts/123/dataSources/456', ['offerId' => '1', 'contentLanguage' => 'uk', 'feedLabel' => 'UA', 'productAttributes' => ['title' => 'Скло']]);

        $request = $this->google->requests[1];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://merchantapi.googleapis.com/products/v1/accounts/123/productInputs:insert?dataSource=accounts/123/dataSources/456', $request['url']);
        $this->assertSame('Bearer token-1', $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertStringContainsString('"title":"Скло"', $request['body']);
    }

    public function testRetriesTransientErrorsThenSucceeds(): void
    {
        $this->google->failures = [FakeGoogle::error(503, 'UNAVAILABLE', 'BACKEND_ERROR'), new HttpResponse(0, '')];

        $this->client()->insertProductInput('accounts/123/dataSources/456', ['offerId' => '1', 'contentLanguage' => 'uk', 'feedLabel' => 'UA']);

        $this->assertSame([1, 3], $this->slept);
        $this->assertCount(1, $this->google->products);
    }

    public function testGivesUpAfterRetriesWithReason(): void
    {
        $this->google->failures = array_fill(0, 3, FakeGoogle::error(503, 'UNAVAILABLE', 'BACKEND_ERROR'));

        try {
            $this->client()->insertProductInput('accounts/123/dataSources/456', ['offerId' => '1', 'contentLanguage' => 'uk', 'feedLabel' => 'UA']);
            $this->fail('no error');
        } catch (ApiError $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('BACKEND_ERROR', $e->reason);
            $this->assertTrue($e->isTransient());
        }
    }

    public function testQuotaIsNotRetried(): void
    {
        $this->google->failures = [FakeGoogle::error(429, 'RESOURCE_EXHAUSTED', 'QUOTA_REQUEST_RATE_TOO_HIGH')];

        try {
            $this->client()->insertProductInput('accounts/123/dataSources/456', ['offerId' => '1', 'contentLanguage' => 'uk', 'feedLabel' => 'UA']);
            $this->fail('no error');
        } catch (ApiError $e) {
            $this->assertTrue($e->isQuota());
            $this->assertSame('QUOTA_REQUEST_RATE_TOO_HIGH', $e->reason);
        }
        $this->assertSame([], $this->slept);
    }

    public function testErrorTextIsCleanedAndReasonValidated(): void
    {
        $response = new HttpResponse(400, json_encode(['error' => [
            'code' => 400,
            'message' => "Bad\r\nvalue <script>",
            'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'metadata' => ['REASON' => 'bad reason!']]],
        ]]));

        $error = ApiError::fromResponse($response);

        $this->assertSame('HTTP_400', $error->reason);
        $this->assertSame('Bad value <script>', $error->getMessage());
        $this->assertFalse($error->isTransient());
    }

    public function testDeleteUsesEncodedIdAndRejectsRawOne(): void
    {
        $client = $this->client();
        $client->insertProductInput('accounts/123/dataSources/456', ['offerId' => '7', 'contentLanguage' => 'uk', 'feedLabel' => 'UA']);
        $id = array_key_first($this->google->products);

        $client->deleteProductInput('accounts/123/dataSources/456', $id);
        $this->assertSame([], $this->google->products);

        $this->expectException(InvalidArgumentException::class);
        $client->deleteProductInput('accounts/123/dataSources/456', '../../accounts/999');
    }

    public function testCreatingDataSourceIsNotRetried(): void
    {
        $this->google->failures = [new HttpResponse(0, ''), new HttpResponse(200, '{"name":"accounts/123/dataSources/9"}')];

        try {
            $this->client()->createApiDataSource('Shop API UK', 'uk', 'UA', ['UA']);
            $this->fail('no error');
        } catch (ApiError $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame([], $this->slept);
        $this->assertCount(1, $this->google->failures, 'second POST would create a duplicate source');
    }

    public function testRejectsForeignDataSourceAndAccount(): void
    {
        try {
            $this->client()->insertProductInput('accounts/123/dataSources/456?x=1', []);
            $this->fail('accepted');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new MerchantClient($this->auth(), $this->google, '123/../456', fn() => null);
    }

    public function testListDataSourcesFollowsPages(): void
    {
        $this->google->failures = [
            new HttpResponse(200, json_encode(['dataSources' => [['name' => 'accounts/123/dataSources/1']], 'nextPageToken' => 'p2'])),
            new HttpResponse(200, json_encode(['dataSources' => [['name' => 'accounts/123/dataSources/2']]])),
        ];

        $sources = $this->client()->listDataSources();

        $this->assertSame(['accounts/123/dataSources/1', 'accounts/123/dataSources/2'], array_column($sources, 'name'));
        $this->assertStringContainsString('pageToken=p2', $this->google->requests[2]['url']);
    }

    private function client(): MerchantClient
    {
        return new MerchantClient($this->auth(), $this->google, '123', function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    private function auth(): GoogleAuth
    {
        return new GoogleAuth(ServiceAccountKey::fromJson(TestKeys::json()), $this->google, fn() => 1_800_000_000);
    }
}
