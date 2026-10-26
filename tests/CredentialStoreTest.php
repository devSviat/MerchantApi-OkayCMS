<?php

namespace Modules\Sviat\MerchantApi;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\MerchantApi\Google\InvalidServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Services\CredentialStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestKeys.php';

class CredentialStoreTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function testSavesOnlyNeededFieldsAndSummaryHidesKey(): void
    {
        $store = $this->store();
        $store->saveJson(TestKeys::json(), new DateTimeImmutable('2026-10-26T12:00:00+02:00'));

        $stored = json_decode($this->values[CredentialStore::KEY], true);
        $this->assertArrayNotHasKey('client_id', $stored);
        $this->assertArrayNotHasKey('token_uri', $stored);

        $summary = $store->summary();
        $this->assertSame('sync@example-project.iam.gserviceaccount.com', $summary['client_email']);
        $this->assertSame('2026-10-26T12:00:00+02:00', $summary['uploaded_at']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($summary));
        $this->assertNotNull($store->load());
    }

    public function testInvalidUploadKeepsPreviousKey(): void
    {
        $store = $this->store();
        $store->saveJson(TestKeys::json(), new DateTimeImmutable());
        $before = $this->values[CredentialStore::KEY];

        try {
            $store->saveJson(TestKeys::json(['token_uri' => 'https://evil.example/token']), new DateTimeImmutable());
        } catch (InvalidServiceAccountKey) {
        }

        $this->assertSame($before, $this->values[CredentialStore::KEY]);
    }

    public function testCorruptedOrDeletedKeyMeansNoKey(): void
    {
        $store = $this->store();
        $this->values[CredentialStore::KEY] = '{"type":"service_account"';
        $this->assertNull($store->load());
        $this->assertNull($store->summary());

        $store->saveJson(TestKeys::json(), new DateTimeImmutable());
        $store->delete();
        $this->assertNull($store->load());
    }

    private function store(): CredentialStore
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn($name) => $this->values[$name] ?? null);
        $settings->method('set')->willReturnCallback(function ($name, $value) use ($settings) {
            $this->values[$name] = $value;

            return $settings;
        });

        return new CredentialStore($settings);
    }
}
