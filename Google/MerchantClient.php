<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

use Closure;
use InvalidArgumentException;
use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use Okay\Modules\Sviat\MerchantApi\Http\HttpTransport;
use Okay\Modules\Sviat\MerchantApi\Http\TransportFailure;

/**
 * Виклики Merchant API v1, потрібні модулю.
 * https://developers.google.com/merchant/api/reference/rest
 */
final class MerchantClient
{
    private const string BASE = 'https://merchantapi.googleapis.com/';

    // Збій мережі чи 5xx повторюємо одразу двічі; далі — наступний запуск задачі.
    private const array RETRY_DELAYS = [1, 3];

    /** @param Closure(int): void $sleep секунди; у тестах — без очікування */
    public function __construct(
        private readonly GoogleAuth $auth,
        private readonly HttpTransport $transport,
        private readonly string $accountId,
        private readonly Closure $sleep,
    ) {
        if (!preg_match('/\A[1-9]\d{0,19}\z/', $accountId)) {
            throw new InvalidArgumentException('Merchant Center account id must be digits');
        }
    }

    /** @param array<string, mixed> $productInput */
    public function insertProductInput(string $dataSource, array $productInput): array
    {
        return $this->call('POST', "products/v1/accounts/{$this->accountId}/productInputs:insert", ['dataSource' => self::dataSource($dataSource)], $productInput);
    }

    /** @param string $productInputId base64url(мова~мітка~ID), LanguageTarget::productInputId() */
    public function deleteProductInput(string $dataSource, string $productInputId): void
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,500}\z/', $productInputId)) {
            throw new InvalidArgumentException('product input id must be base64url');
        }
        $this->call('DELETE', "products/v1/accounts/{$this->accountId}/productInputs/{$productInputId}", ['dataSource' => self::dataSource($dataSource)]);
    }

    /** @return list<array<string, mixed>> */
    public function listDataSources(): array
    {
        $sources = [];
        $pageToken = null;
        do {
            $page = $this->call('GET', "datasources/v1/accounts/{$this->accountId}/dataSources", array_filter(['pageSize' => '1000', 'pageToken' => $pageToken]));
            foreach (is_array($page['dataSources'] ?? null) ? $page['dataSources'] : [] as $source) {
                $sources[] = $source;
            }
            $pageToken = is_string($page['nextPageToken'] ?? null) && $page['nextPageToken'] !== '' ? $page['nextPageToken'] : null;
        } while ($pageToken !== null);

        return $sources;
    }

    /**
     * Основне API-джерело однієї мови. Мова й мітка незмінні після створення.
     *
     * @param list<string> $countries CLDR, напр. ['UA']
     * @param list<array{destination: string, state: string}> $destinations з файлового джерела, яке API-джерело
     *        заміняє; без них Google ставить свої типові способи показу, і товари могли б зникнути з реклами
     * @return array<string, mixed>
     */
    public function createApiDataSource(string $displayName, string $contentLanguage, string $feedLabel, array $countries, array $destinations = []): array
    {
        $primary = [
            'contentLanguage' => $contentLanguage,
            'feedLabel' => $feedLabel,
            'countries' => $countries,
        ];
        if ($destinations !== []) {
            $primary['destinations'] = $destinations;
        }

        // Створення не ідемпотентне: повтор після таймауту міг би лишити два джерела з однаковою мовою й міткою.
        return $this->call('POST', "datasources/v1/accounts/{$this->accountId}/dataSources", [], [
            'displayName' => mb_substr($displayName, 0, 100),
            'primaryProductDataSource' => $primary,
        ], retry: false);
    }

    /**
     * Одноразова реєстрація проєкту Google Cloud. До неї решта викликів — 401 UNAUTHENTICATED «GCP project …
     * is not registered»; після неї, доки в акаунті немає підтвердженої людини з роллю API_DEVELOPER, —
     * «should have at least one user with 'API_DEVELOPER' role» (обидва бачили 10.11.2026 на живому акаунті).
     */
    public function registerGcp(string $developerEmail): array
    {
        return $this->call('POST', "accounts/v1/accounts/{$this->accountId}/developerRegistration:registerGcp", [], ['developerEmail' => $developerEmail]);
    }

    /**
     * Сторінка оброблених товарів зі статусами.
     *
     * @return array{products: list<array<string, mixed>>, nextPageToken: ?string}
     */
    public function listProducts(?string $pageToken = null): array
    {
        $page = $this->call('GET', "products/v1/accounts/{$this->accountId}/products", array_filter(['pageSize' => '1000', 'pageToken' => $pageToken]));

        return [
            'products' => array_values(array_filter(is_array($page['products'] ?? null) ? $page['products'] : [], is_array(...))),
            'nextPageToken' => is_string($page['nextPageToken'] ?? null) && $page['nextPageToken'] !== '' ? $page['nextPageToken'] : null,
        ];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     * @throws ApiError
     * @throws AuthFailure
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null, bool $retry = true): array
    {
        $url = self::BASE . $path . ($query === [] ? '' : '?' . self::query($query));
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send($method, $url, array_filter([
                    'Authorization' => 'Bearer ' . $this->auth->accessToken(),
                    'Content-Type' => $payload === null ? null : 'application/json',
                ]), $payload);
            } catch (TransportFailure) {
                $response = new HttpResponse(0, '');
            }

            if ($response->isSuccess()) {
                return $response->json() ?? [];
            }

            $error = ApiError::fromResponse($response);
            if (!$retry || !$error->isTransient() || $attempt >= count(self::RETRY_DELAYS)) {
                throw $error;
            }
            ($this->sleep)(self::RETRY_DELAYS[$attempt]);
            $attempt++;
        }
    }

    /** Імена ресурсів Google лишаються з «/» (так у документації), решта — rawurlencode. */
    private static function query(array $query): string
    {
        $parts = [];
        foreach ($query as $name => $value) {
            $parts[] = rawurlencode($name) . '=' . str_replace('%2F', '/', rawurlencode((string) $value));
        }

        return implode('&', $parts);
    }

    private static function dataSource(string $dataSource): string
    {
        if (!preg_match('~\Aaccounts/\d+/dataSources/\d+\z~', $dataSource)) {
            throw new InvalidArgumentException('data source must be accounts/{id}/dataSources/{id}');
        }

        return $dataSource;
    }
}
