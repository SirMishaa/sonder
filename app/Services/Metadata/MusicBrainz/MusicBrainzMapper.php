<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Services\Metadata\Data\RegistryRecording;
use App\Services\Metadata\Data\WeightedTag;
use App\Support\ReleaseDate;

/**
 * Turns MusicBrainz payloads into Data objects. Pure.
 */
final class MusicBrainzMapper
{
    /**
     * @param  array<array-key, mixed>  $payload  an `isrc` lookup or a recording search
     * @return list<RegistryRecording>
     */
    public static function recordings(array $payload): array
    {
        return array_map(self::recording(...), self::rows($payload['recordings'] ?? null));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function recording(array $payload): RegistryRecording
    {
        $length = $payload['length'] ?? null;
        $score = $payload['score'] ?? null;

        return new RegistryRecording(
            mbid: self::string($payload['id'] ?? null) ?? '',
            title: self::string($payload['title'] ?? null) ?? '',
            durationSeconds: is_int($length) ? intdiv($length + 500, 1000) : null,
            artists: array_values(array_filter(array_map(function (array $credit): ?array {
                $artist = self::map($credit['artist'] ?? null);
                $mbid = self::string($artist['id'] ?? null);
                $name = self::string($artist['name'] ?? null) ?? self::string($credit['name'] ?? null);

                return $mbid !== null && $name !== null ? ['mbid' => $mbid, 'name' => $name] : null;
            }, self::rows($payload['artist-credit'] ?? null)))),
            isrcs: array_values(array_filter(self::map($payload['isrcs'] ?? null), is_string(...))),
            score: is_int($score) ? $score : (is_numeric($score) ? (int) $score : null),
            firstReleaseDate: ReleaseDate::normalize($payload['first-release-date'] ?? null),
            disambiguation: self::string($payload['disambiguation'] ?? null),
        );
    }

    /**
     * Genres and tags, merged by name, weighted by votes against the most
     * voted one.
     *
     * @param  array<array-key, mixed>  $payload  a recording or artist lookup
     * @return list<WeightedTag>
     */
    public static function tags(array $payload): array
    {
        $genres = [];
        $counts = [];

        foreach (self::rows($payload['genres'] ?? null) as $genre) {
            $name = mb_strtolower(self::string($genre['name'] ?? null) ?? '');
            $genres[$name] = true;
            $counts[$name] = max($counts[$name] ?? 0, self::count($genre['count'] ?? null));
        }

        foreach (self::rows($payload['tags'] ?? null) as $tag) {
            $name = mb_strtolower(self::string($tag['name'] ?? null) ?? '');
            $counts[$name] = max($counts[$name] ?? 0, self::count($tag['count'] ?? null));
        }

        unset($counts['']);
        $top = max([1, ...array_values($counts)]);
        $tags = [];

        foreach ($counts as $name => $count) {
            $tags[] = new WeightedTag((string) $name, (int) round($count / $top * 100), isset($genres[$name]));
        }

        return $tags;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $value): array
    {
        return array_values(array_filter(self::map($value), is_array(...)));
    }

    private static function count(mixed $value): int
    {
        return is_int($value) ? max(0, $value) : 0;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
