<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

use Closure;
use Okay\Modules\Sviat\MerchantApi\Http\HttpTransport;

/**
 * OAuth 2.0 для сервісного акаунта: підписаний RS256 JWT → токен доступу.
 * https://developers.google.com/identity/protocols/oauth2/service-account
 */
final class GoogleAuth
{
    public const string SCOPE = 'https://www.googleapis.com/auth/content';

    // Google відхиляє JWT, якщо exp - iat > 3600; iat у минулому прощає розбіжність годинників.
    private const int LIFETIME = 3600;
    private const int CLOCK_SKEW = 60;
    // Токен беремо новий трохи раніше: запит, що стартував за секунду до кінця, отримав би 401.
    private const int REFRESH_MARGIN = 120;

    private ?string $token = null;
    private int $expiresAt = 0;

    /** @param Closure(): int $now unix-час */
    public function __construct(
        private readonly ServiceAccountKey $key,
        private readonly HttpTransport $transport,
        private readonly Closure $now,
    ) {
    }

    /** @throws AuthFailure */
    public function accessToken(): string
    {
        $now = ($this->now)();
        if ($this->token !== null && $now < $this->expiresAt - self::REFRESH_MARGIN) {
            return $this->token;
        }

        $response = $this->transport->send('POST', ServiceAccountKey::TOKEN_URI, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion($now),
        ]));

        $data = $response->json() ?? [];
        if (!$response->isSuccess() || !is_string($data['access_token'] ?? null) || !is_int($data['expires_in'] ?? null)) {
            // error/error_description від Google не містять секретів і пояснюють причину (invalid_grant тощо).
            $error = $data['error'] ?? null;
            $reason = is_string($error) && preg_match('/\A[a-z_]{1,64}\z/', $error) ? $error : 'http_' . $response->status;
            throw new AuthFailure($reason);
        }

        $this->token = $data['access_token'];
        $this->expiresAt = $now + $data['expires_in'];

        return $this->token;
    }

    public function assertion(int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->key->privateKeyId];
        $claims = [
            'iss' => $this->key->clientEmail,
            'scope' => self::SCOPE,
            'aud' => ServiceAccountKey::TOKEN_URI,
            'iat' => $now - self::CLOCK_SKEW,
            'exp' => $now - self::CLOCK_SKEW + self::LIFETIME,
        ];
        $unsigned = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (!openssl_sign($unsigned, $signature, $this->key->privateKeyPem(), OPENSSL_ALGO_SHA256)) {
            throw new AuthFailure('sign_failed');
        }

        return $unsigned . '.' . self::base64Url($signature);
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
