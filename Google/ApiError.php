<?php

namespace Okay\Modules\Sviat\MerchantApi\Google;

use Okay\Modules\Sviat\MerchantApi\Http\HttpResponse;
use RuntimeException;

/**
 * Відмова Merchant API. Причина — стабільний REASON з ErrorInfo (радить Google), а не текст повідомлення.
 * https://developers.google.com/merchant/api/guides/error-handling
 */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function fromResponse(HttpResponse $response): self
    {
        $error = $response->json()['error'] ?? null;
        $error = is_array($error) ? $error : [];

        $reason = null;
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            if (is_array($detail) && str_ends_with((string) ($detail['@type'] ?? ''), 'google.rpc.ErrorInfo')) {
                $reason = $detail['metadata']['REASON'] ?? $detail['reason'] ?? null;
                break;
            }
        }
        $reason ??= $error['status'] ?? null;
        $reason = is_string($reason) && preg_match('~\A[A-Za-z0-9_./]{1,64}\z~', $reason) ? $reason : 'HTTP_' . $response->status;

        return new self($response->status, $reason, self::clean($error['message'] ?? ''));
    }

    /** Для журналу й адмінки: код, причина і текст Google — без нього «401:UNAUTHENTICATED» не каже, що саме не так. */
    public function describe(): string
    {
        $message = $this->getMessage();

        return "{$this->status}:{$this->reason}" . ($message === '' ? '' : ": {$message}");
    }

    public function isQuota(): bool
    {
        return $this->status === 429;
    }

    /** Мережа/сервер Google — є сенс повторити пізніше. */
    public function isTransient(): bool
    {
        return $this->status >= 500 || $this->status === 0;
    }

    /** Ключ, реєстрація проєкту, доступ до акаунта — далі слати немає сенсу, доки людина не виправить. */
    public function isFatal(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    /** Текст від Google — для людини в адмінці: без керівних символів, обрізаний. */
    private static function clean(mixed $message): string
    {
        $text = is_string($message) ? preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) : '';

        return mb_substr(trim((string) $text), 0, 300);
    }
}
