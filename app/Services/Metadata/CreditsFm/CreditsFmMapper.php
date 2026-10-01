<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Enums\CreditType;
use App\Services\Metadata\Data\Credit;
use App\Services\Metadata\Data\IsrcDetail;
use App\Support\ReleaseDate;

/**
 * Turns credits.fm payloads into Data objects. Pure.
 */
final class CreditsFmMapper
{
    /**
     * @param  array<array-key, mixed>  $payload  a `GET /v1/isrc/{isrc}` answer
     */
    public static function detail(array $payload): IsrcDetail
    {
        return new IsrcDetail(
            isrc: self::string($payload['isrc'] ?? null) ?? '',
            title: self::string($payload['recording_title'] ?? null) ?? '',
            artists: self::strings($payload['artist_names'] ?? null),
            iswc: self::string($payload['iswc'] ?? null),
            releaseDate: ReleaseDate::normalize($payload['release_date'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload  a `GET /v1/isrc/{isrc}` answer
     * @return list<Credit>
     */
    public static function credits(array $payload): array
    {
        $credits = [];

        foreach (self::rows($payload['recording_artists'] ?? null) as $artist) {
            $credits[] = new Credit(self::string($artist['name'] ?? null) ?? '', CreditType::Artist, '', self::string($artist['mbid'] ?? null), null);
        }

        foreach (self::rows($payload['songwriters'] ?? null) as $writer) {
            $credits[] = new Credit(self::string($writer['name'] ?? null) ?? '', CreditType::Songwriter, self::string($writer['role'] ?? null) ?? '', null, self::string($writer['ipi'] ?? null));

            foreach (self::rows($writer['publishers'] ?? null) as $publisher) {
                $credits[] = new Credit(self::string($publisher['name'] ?? null) ?? '', CreditType::Publisher, self::string($publisher['role'] ?? null) ?? '', null, self::string($publisher['ipi'] ?? null));
            }
        }

        foreach (self::rows($payload['performers'] ?? null) as $performer) {
            $credits[] = new Credit(
                name: self::string($performer['name'] ?? null) ?? '',
                type: ($performer['credit_type'] ?? null) === 'producer' ? CreditType::Producer : CreditType::Performer,
                role: self::string($performer['role'] ?? null) ?? '',
                mbid: self::string($performer['mbid'] ?? null),
                ipi: null,
                attributes: self::strings($performer['attributes'] ?? null),
            );
        }

        $unique = [];

        foreach ($credits as $credit) {
            if ($credit->name !== '') {
                $unique[$credit->key()] ??= $credit;
            }
        }

        return array_values($unique);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(self::string(...), $value), is_string(...))) : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
