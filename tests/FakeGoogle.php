<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Http\CurlTransport;
use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use Okay\Modules\Sviat\MerchantApi\Http\HttpTransport;

/**
 * Merchant API в пам'яті: токен, productInputs insert/delete, задані відмови.
 * Як у Google: товар — це мова~мітка~ID, джерело — його властивість; insert в інше джерело переносить товар,
 * delete з чужого джерела — 404. Перевіряє ті самі правила адреси, що й справжній транспорт.
 */
final class FakeGoogle implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> productInput id → тіло */
    public array $products = [];

    /** @var array<string, string> productInput id → джерело */
    public array $sourceOf = [];

    /** @var list<HttpResponse> відповіді на наступні виклики API (не токена), по черзі */
    public array $failures = [];

    public int $tokens = 0;

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        CurlTransport::assertAllowed($url);
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($url === 'https://oauth2.googleapis.com/token') {
            $this->tokens++;

            return new HttpResponse(200, json_encode(['access_token' => "token-{$this->tokens}", 'expires_in' => 3599]));
        }
        if (($headers['Authorization'] ?? '') !== "Bearer token-{$this->tokens}") {
            return self::error(401, 'UNAUTHENTICATED', 'ACCESS_TOKEN_INVALID');
        }
        if ($this->failures !== []) {
            return array_shift($this->failures);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $source = (string) ($query['dataSource'] ?? '');
        if ($method === 'POST' && str_ends_with($path, '/productInputs:insert')) {
            $input = json_decode((string) $body, true);
            $id = self::id($input['contentLanguage'], $input['feedLabel'], $input['offerId']);
            $this->products[$id] = $input;
            $this->sourceOf[$id] = $source;

            return new HttpResponse(200, json_encode($input + ['name' => "accounts/123/productInputs/{$id}"]));
        }
        if ($method === 'DELETE' && preg_match('~/productInputs/([A-Za-z0-9_-]+)$~', $path, $m)) {
            if (!isset($this->products[$m[1]]) || $this->sourceOf[$m[1]] !== $source) {
                return self::error(404, 'NOT_FOUND', 'NOT_FOUND');
            }
            unset($this->products[$m[1]], $this->sourceOf[$m[1]]);

            return new HttpResponse(200, '{}');
        }

        return self::error(404, 'NOT_FOUND', 'NOT_FOUND');
    }

    public static function id(string $language, string $label, string $offerId): string
    {
        return rtrim(strtr(base64_encode("{$language}~{$label}~{$offerId}"), '+/', '-_'), '=');
    }

    public static function error(int $status, string $grpcStatus, string $reason, string $message = 'fake'): HttpResponse
    {
        return new HttpResponse($status, json_encode(['error' => [
            'code' => $status,
            'message' => $message,
            'status' => $grpcStatus,
            'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => $reason,
                'metadata' => ['REASON' => $reason],
            ]],
        ]]));
    }

    /** @return list<string> «METHOD шлях» викликів API без токена */
    public function apiCalls(): array
    {
        $calls = [];
        foreach ($this->requests as $request) {
            if ($request['url'] !== 'https://oauth2.googleapis.com/token') {
                $calls[] = $request['method'] . ' ' . parse_url($request['url'], PHP_URL_PATH);
            }
        }

        return $calls;
    }
}
