<?php

namespace Okay\Modules\Sviat\MerchantApi\Services;

/**
 * Чинне файлове джерело мови, яке API-джерело заміняє. З нього беруться мітка фіду (вона входить в ID
 * товару в Google, тож мусить збігтися, інакше вийдуть нові товари без історії) і способи показу.
 */
final class FileSourceMatch
{
    /**
     * Мова файлового джерела може бути з регіоном («ru-UA»), а товари в ньому Google все одно веде
     * з мовою «ru» — тож порівнюються дві перші літери.
     *
     * @param list<array<string, mixed>> $sources рядки ModuleSettings::sourcesCache()
     * @return array<string, mixed>|null null — немає жодного або їх кілька (вгадувати мітку не можна)
     */
    public static function forLanguage(array $sources, string $contentLanguage): ?array
    {
        $found = [];
        foreach ($sources as $source) {
            $language = strtolower(substr((string) ($source['content_language'] ?? ''), 0, 2));
            if (($source['input'] ?? null) === 'FILE' && ($source['primary'] ?? false) === true && $language !== '' && $language === $contentLanguage) {
                $found[] = $source;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
