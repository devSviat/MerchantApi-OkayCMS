<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Google\InvalidServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey;

/**
 * Ключ сервісного акаунта в налаштуваннях модуля — як секрети інших модулів OkayCMS
 * (обґрунтування — docs/superpowers/specs/2026-10-26-merchant-api-design.md §11).
 * Назовні віддається лише summary(); сам ключ — тільки тим, хто йде в Google.
 */
class CredentialStore
{
    public const string KEY = 'sviat__merchant_api__service_account';
    public const string UPLOADED_AT = 'sviat__merchant_api__service_account_uploaded_at';

    public function __construct(private Settings $settings)
    {
    }

    /** @throws InvalidServiceAccountKey */
    public function saveJson(string $json, \DateTimeImmutable $now): ServiceAccountKey
    {
        $key = ServiceAccountKey::fromJson($json);
        $this->settings->set(self::KEY, json_encode($key->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->settings->set(self::UPLOADED_AT, $now->format(DATE_ATOM));

        return $key;
    }

    /** null — ключа немає або збережене значення зіпсоване (тоді синхронізація стоїть, адмін бачить «немає ключа»). */
    public function load(): ?ServiceAccountKey
    {
        $stored = $this->settings->get(self::KEY);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return ServiceAccountKey::fromJson($stored);
        } catch (InvalidServiceAccountKey) {
            return null;
        }
    }

    /** @return array{client_email: string, private_key_id: string, project_id: string, uploaded_at: ?string}|null */
    public function summary(): ?array
    {
        $key = $this->load();
        if ($key === null) {
            return null;
        }
        $uploadedAt = $this->settings->get(self::UPLOADED_AT);

        return [
            'client_email' => $key->clientEmail,
            'private_key_id' => $key->privateKeyId,
            'project_id' => $key->projectId,
            'uploaded_at' => is_string($uploadedAt) && $uploadedAt !== '' ? $uploadedAt : null,
        ];
    }

    public function delete(): void
    {
        $this->settings->set(self::KEY, '');
        $this->settings->set(self::UPLOADED_AT, '');
    }
}
