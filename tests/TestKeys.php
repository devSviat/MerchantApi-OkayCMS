<?php

namespace Modules\Sviat\MerchantApi;

/** Ключі генеруються в процесі тестів: у репозиторії (публічному) не лежить жоден приватний ключ. */
final class TestKeys
{
    private static ?string $pem = null;

    public static function rsaPem(): string
    {
        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$pem = $pem;
        }

        return self::$pem;
    }

    public static function publicPem(): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private(self::rsaPem()))['key'];
    }

    /** @param array<string, mixed> $override */
    public static function json(array $override = []): string
    {
        return json_encode(array_merge([
            'type' => 'service_account',
            'project_id' => 'example-project',
            'private_key_id' => str_repeat('ab', 20),
            'private_key' => self::rsaPem(),
            'client_email' => 'sync@example-project.iam.gserviceaccount.com',
            'client_id' => '123',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], $override), JSON_UNESCAPED_SLASHES);
    }
}
