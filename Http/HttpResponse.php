<?php

namespace Okay\Modules\Sviat\MerchantApi\Http;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers імена в нижньому регістрі */
    public function __construct(public int $status, public string $body, public array $headers = [])
    {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<mixed>|null null — тіло не JSON-об'єкт */
    public function json(): ?array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
