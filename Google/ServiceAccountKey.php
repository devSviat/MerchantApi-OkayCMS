<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

/**
 * Ключ сервісного акаунта Google: лише поля, потрібні для OAuth.
 * JSON із Google Cloud приймається тільки після перевірки — підмінений `token_uri` віддав би ключ чужому серверу.
 */
final readonly class ServiceAccountKey
{
    public const string TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        public string $clientEmail,
        public string $privateKeyId,
        private string $privateKey,
        public string $projectId,
    ) {
    }

    /** PEM — лише для підпису JWT (GoogleAuth); у властивості не лежить на виду. */
    public function privateKeyPem(): string
    {
        return $this->privateKey;
    }

    /** @throws InvalidServiceAccountKey код причини в getReason() */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::NOT_JSON);
        }
        if (!is_array($data)) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::NOT_JSON);
        }

        return self::fromArray($data);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'service_account') {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::NOT_SERVICE_ACCOUNT);
        }
        // Відсутній token_uri допустимий (збережений ключ його не має); присутній — лише справжній.
        if (array_key_exists('token_uri', $data) && $data['token_uri'] !== self::TOKEN_URI) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::FOREIGN_TOKEN_URI);
        }

        $clientEmail = self::string($data, 'client_email');
        $privateKeyId = self::string($data, 'private_key_id');
        $privateKey = self::string($data, 'private_key');
        $projectId = self::string($data, 'project_id');

        if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($clientEmail, '.gserviceaccount.com')) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::BAD_CLIENT_EMAIL);
        }
        if (!preg_match('/^[a-f0-9]{40}$/', $privateKeyId)) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::BAD_FIELD);
        }
        // openssl_pkey_get_private читає й «file://шлях»: без цієї перевірки менеджер міг би змусити модуль
        // підписувати будь-яким RSA-ключем із сервера й зондувати наявність файлів.
        if (!str_starts_with(ltrim($privateKey), '-----BEGIN ')) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::BAD_PRIVATE_KEY);
        }
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::BAD_PRIVATE_KEY);
        }

        return new self($clientEmail, $privateKeyId, $privateKey, $projectId);
    }

    /** @return array{type: string, client_email: string, private_key_id: string, private_key: string, project_id: string} */
    public function toArray(): array
    {
        return [
            'type' => 'service_account',
            'client_email' => $this->clientEmail,
            'private_key_id' => $this->privateKeyId,
            'private_key' => $this->privateKey,
            'project_id' => $this->projectId,
        ];
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidServiceAccountKey(InvalidServiceAccountKey::BAD_FIELD);
        }

        return $value;
    }

    /** Ключ не потрапляє в var_dump/print_r. */
    public function __debugInfo(): array
    {
        return ['clientEmail' => $this->clientEmail, 'privateKeyId' => $this->privateKeyId, 'projectId' => $this->projectId];
    }

    /** Серіалізація — шлях ключа в кеш, сесію чи лог; зберігати — лише через toArray() у CredentialStore. */
    public function __serialize(): array
    {
        throw new \LogicException('ServiceAccountKey is not serializable');
    }
}
