<?php

namespace Okay\Modules\Sviat\MerchantApi\Http;

/**
 * Єдиний вихід модуля в мережу. Адреси зашиті тут, а не в налаштуваннях: інакше поле в адмінці
 * дозволило б надсилати токени й ключ на довільний сервер (SSRF).
 */
final class CurlTransport implements HttpTransport
{
    public const array ALLOWED_HOSTS = ['oauth2.googleapis.com', 'merchantapi.googleapis.com'];

    // Google радить таймаут 60 с; з'єднання довше 10 с — уже збій мережі.
    private const int CONNECT_TIMEOUT = 10;
    private const int TIMEOUT = 60;

    /**
     * Один дескриптор на процес: curl_reset() скидає опції, але лишає живе з'єднання, тож запуск із сотень
     * вставок не робить TCP і TLS заново на кожен товар (заміряно: ~230 → ~150 мс на запит).
     */
    private ?\CurlHandle $handle = null;

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        self::assertAllowed($url);

        $handle = $this->handle ??= curl_init() ?: throw new TransportFailure('curl_init failed');
        curl_reset($handle);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        // Після curl_reset() тіло попереднього запиту вже скинуте; без тіла тут — запит без тіла (DELETE, GET).
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        if (!is_string($result)) {
            // Текст помилки curl безпечний: у ньому немає ні заголовків, ні тіла запиту.
            throw new TransportFailure('curl: ' . curl_error($handle));
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $result, $responseHeaders);
    }

    /**
     * Строгий шаблон замість parse_url: parse_url і curl по-різному читають `@`, `#`, `\` і пробіли,
     * і на цій різниці будуються обходи перевірки хоста.
     */
    public static function assertAllowed(string $url): void
    {
        $hosts = implode('|', array_map(static fn(string $host): string => preg_quote($host, '#'), self::ALLOWED_HOSTS));
        if (!preg_match('#\Ahttps://(?:' . $hosts . ')/[A-Za-z0-9/_.:~%?=&+-]*\z#', $url)) {
            throw new TransportFailure('URL is not an allowed Google endpoint');
        }
    }
}
