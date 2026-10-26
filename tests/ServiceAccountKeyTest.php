<?php

namespace Modules\Sviat\MerchantApi;

use Okay\Modules\Sviat\MerchantApi\Google\InvalidServiceAccountKey;
use Okay\Modules\Sviat\MerchantApi\Google\ServiceAccountKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestKeys.php';

class ServiceAccountKeyTest extends TestCase
{
    public function testValidKeyKeepsOnlyNeededFields(): void
    {
        $key = ServiceAccountKey::fromJson(TestKeys::json());

        $this->assertSame('sync@example-project.iam.gserviceaccount.com', $key->clientEmail);
        $this->assertSame(
            ['type', 'client_email', 'private_key_id', 'private_key', 'project_id'],
            array_keys($key->toArray())
        );
        $this->assertEquals($key, ServiceAccountKey::fromJson(json_encode($key->toArray())));
    }

    /** @return array<string, array{string, string}> */
    public static function invalid(): array
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $ecPem);

        return [
            'not json' => ['{', InvalidServiceAccountKey::NOT_JSON],
            'scalar json' => ['"x"', InvalidServiceAccountKey::NOT_JSON],
            'user credentials' => [TestKeys::json(['type' => 'authorized_user']), InvalidServiceAccountKey::NOT_SERVICE_ACCOUNT],
            'foreign token uri' => [TestKeys::json(['token_uri' => 'https://evil.example/token']), InvalidServiceAccountKey::FOREIGN_TOKEN_URI],
            'token uri over http' => [TestKeys::json(['token_uri' => 'http://oauth2.googleapis.com/token']), InvalidServiceAccountKey::FOREIGN_TOKEN_URI],
            'non google email' => [TestKeys::json(['client_email' => 'sync@example.com']), InvalidServiceAccountKey::BAD_CLIENT_EMAIL],
            'garbage key' => [TestKeys::json(['private_key' => 'nope']), InvalidServiceAccountKey::BAD_PRIVATE_KEY],
            'ec key' => [TestKeys::json(['private_key' => $ecPem]), InvalidServiceAccountKey::BAD_PRIVATE_KEY],
            'empty key id' => [TestKeys::json(['private_key_id' => '']), InvalidServiceAccountKey::BAD_FIELD],
            'odd key id' => [TestKeys::json(['private_key_id' => '../x']), InvalidServiceAccountKey::BAD_FIELD],
            'missing project' => [TestKeys::json(['project_id' => null]), InvalidServiceAccountKey::BAD_FIELD],
        ];
    }

    #[DataProvider('invalid')]
    public function testRejects(string $json, string $reason): void
    {
        try {
            ServiceAccountKey::fromJson($json);
            $this->fail('accepted');
        } catch (InvalidServiceAccountKey $e) {
            $this->assertSame($reason, $e->getReason());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    public function testDumpsAndEncodingHidePrivateKey(): void
    {
        $key = ServiceAccountKey::fromJson(TestKeys::json());

        $this->assertStringNotContainsString('PRIVATE KEY', print_r($key, true));
        ob_start();
        var_dump($key);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) ob_get_clean());
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($key));
        $this->assertStringContainsString('PRIVATE KEY', $key->privateKeyPem());

        $this->expectException(\LogicException::class);
        serialize($key);
    }

    public function testFilePathInsteadOfPemIsRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($file, TestKeys::rsaPem());
        try {
            ServiceAccountKey::fromJson(TestKeys::json(['private_key' => 'file://' . $file]));
            $this->fail('file path accepted');
        } catch (InvalidServiceAccountKey $e) {
            $this->assertSame(InvalidServiceAccountKey::BAD_PRIVATE_KEY, $e->getReason());
        } finally {
            unlink($file);
        }
    }
}
