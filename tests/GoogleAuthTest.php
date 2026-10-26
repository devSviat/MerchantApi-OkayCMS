<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Google\AuthFailure;
use Okay\Modules\Sviat\MerchantApi\Google\GoogleAuth;
use Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use Okay\Modules\Sviat\MerchantApi\Http\HttpTransport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestKeys.php';

class GoogleAuthTest extends TestCase
{
    private int $now = 1_800_000_000;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $sent = [];

    /** @var list<HttpResponse> */
    private array $responses = [];

    public function testAssertionIsVerifiableRs256JwtForGoogle(): void
    {
        $jwt = $this->auth()->assertion($this->now);
        [$h, $c, $s] = explode('.', $jwt);

        $header = json_decode(self::decode($h), true);
        $claims = json_decode(self::decode($c), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => str_repeat('ab', 20)], $header);
        $this->assertSame('sync@example-project.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/content', $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
        $this->assertLessThanOrEqual($this->now, $claims['iat']);
        $this->assertGreaterThan($this->now, $claims['exp']);
        $this->assertSame(1, openssl_verify("{$h}.{$c}", self::decode($s), TestKeys::publicPem(), OPENSSL_ALGO_SHA256));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $jwt);
    }

    public function testFetchesTokenOnceAndReusesItUntilNearExpiry(): void
    {
        $this->responses = [
            new HttpResponse(200, '{"access_token":"t1","expires_in":3599,"token_type":"Bearer"}'),
            new HttpResponse(200, '{"access_token":"t2","expires_in":3599,"token_type":"Bearer"}'),
        ];
        $auth = $this->auth();

        $this->assertSame('t1', $auth->accessToken());
        $this->now += 3000;
        $this->assertSame('t1', $auth->accessToken());
        $this->assertCount(1, $this->sent);

        $this->now += 500;
        $this->assertSame('t2', $auth->accessToken());
        $this->assertCount(2, $this->sent);

        $request = $this->sent[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://oauth2.googleapis.com/token', $request['url']);
        parse_str((string) $request['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $this->assertCount(3, explode('.', $form['assertion']));
    }

    public function testGoogleErrorBecomesSafeReason(): void
    {
        $this->responses = [new HttpResponse(400, '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}')];

        try {
            $this->auth()->accessToken();
            $this->fail('no failure');
        } catch (AuthFailure $e) {
            $this->assertSame('invalid_grant', $e->getReason());
        }
    }

    public function testUnexpectedErrorTextIsNotEchoed(): void
    {
        $this->responses = [new HttpResponse(500, '{"error":"<script>alert(1)</script>"}')];

        try {
            $this->auth()->accessToken();
            $this->fail('no failure');
        } catch (AuthFailure $e) {
            $this->assertSame('http_500', $e->getReason());
        }
    }

    public function testSuccessWithoutTokenIsFailure(): void
    {
        $this->responses = [new HttpResponse(200, '<html>proxy</html>')];

        $this->expectException(AuthFailure::class);
        $this->auth()->accessToken();
    }

    private function auth(): GoogleAuth
    {
        $transport = new class ($this) implements HttpTransport {
            public function __construct(private GoogleAuthTest $test)
            {
            }

            public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
            {
                return $this->test->record($method, $url, $headers, $body);
            }
        };

        return new GoogleAuth(ServiceAccountKey::fromJson(TestKeys::json()), $transport, fn() => $this->now);
    }

    /** @param array<string, string> $headers */
    public function record(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->responses) ?? new HttpResponse(500, '');
    }

    private static function decode(string $part): string
    {
        return base64_decode(strtr($part, '-_', '+/'));
    }
}
