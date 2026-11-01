<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

/**
 * Одна мова сайту → одне API-джерело даних у Merchant Center.
 * Мова й мітка фіду в Google незмінні після створення джерела, і разом з ID товару
 * визначають товар — тож для переходу з файлу мітка має збігатися з чинним джерелом.
 */
final readonly class LanguageTarget
{
    private function __construct(
        public int $langId,
        public string $contentLanguage,
        public string $feedLabel,
        public int $feedId,
        public ?string $dataSource,
        public bool $removing = false,
    ) {
    }

    public static function create(int $langId, string $contentLanguage, string $feedLabel, int $feedId, ?string $dataSource, bool $removing = false): ?self
    {
        $valid = $langId > 0
            && $feedId > 0
            && preg_match('/\A[a-z]{2}\z/', $contentLanguage)
            // Обмеження Google на мітку фіду: до 20 символів, A-Z, 0-9, «-» і «_».
            && preg_match('/\A[A-Z0-9_-]{1,20}\z/', $feedLabel)
            && ($dataSource === null || preg_match('~\Aaccounts/\d+/dataSources/\d+\z~', $dataSource));

        return $valid ? new self($langId, $contentLanguage, $feedLabel, $feedId, $dataSource, $removing) : null;
    }

    /** @param array<mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $dataSource = $row['data_source'] ?? null;

        return self::create(
            is_numeric($row['lang_id'] ?? null) ? (int) $row['lang_id'] : 0,
            is_string($row['content_language'] ?? null) ? $row['content_language'] : '',
            is_string($row['feed_label'] ?? null) ? $row['feed_label'] : '',
            is_numeric($row['feed_id'] ?? null) ? (int) $row['feed_id'] : 0,
            is_string($dataSource) && $dataSource !== '' ? $dataSource : null,
            ($row['removing'] ?? false) === true,
        );
    }

    /** @return array{lang_id: int, content_language: string, feed_label: string, feed_id: int, data_source: ?string, removing: bool} */
    public function toArray(): array
    {
        return [
            'lang_id' => $this->langId,
            'content_language' => $this->contentLanguage,
            'feed_label' => $this->feedLabel,
            'feed_id' => $this->feedId,
            'data_source' => $this->dataSource,
            'removing' => $this->removing,
        ];
    }

    public function withRemoving(bool $removing): self
    {
        return new self($this->langId, $this->contentLanguage, $this->feedLabel, $this->feedId, $this->dataSource, $removing);
    }

    /** Ті самі товари в Google: ID товару там — мова~мітка~ID, джерело в нього не входить. */
    public function sameProductsAs(self $other): bool
    {
        return $this->contentLanguage === $other->contentLanguage && $this->feedLabel === $other->feedLabel;
    }

    /** ID ресурсу ProductInput: base64url(мова~мітка~ID) — Google радить так для всіх ID. */
    public function productInputId(string $offerId): string
    {
        return rtrim(strtr(base64_encode("{$this->contentLanguage}~{$this->feedLabel}~{$offerId}"), '+/', '-_'), '=');
    }
}
