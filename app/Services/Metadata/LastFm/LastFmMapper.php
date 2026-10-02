<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Services\Metadata\Data\ChartPosition;
use App\Services\Metadata\Data\LastFmTrack;
use App\Services\Metadata\Data\Popularity;
use App\Services\Metadata\Data\SimilarArtist;
use App\Services\Metadata\Data\SimilarTrack;
use App\Services\Metadata\Data\WeightedTag;
use App\Support\MusicText;

/**
 * Turns Last.fm payloads into Data objects. Last.fm sends numbers as
 * strings, durations in milliseconds in `getInfo` (often "0") but in seconds
 * elsewhere, and tags as a folksonomy: they are never treated as genres.
 */
final class LastFmMapper
{
    public const int MAX_TAGS = 20;

    public const int MIN_TAG_WEIGHT = 5;

    /**
     * @param  array<string, mixed>  $payload  a track.getInfo answer
     */
    public static function track(array $payload): ?LastFmTrack
    {
        $track = self::map($payload['track'] ?? null);
        $title = self::string($track['name'] ?? null);
        $artist = self::string(self::map($track['artist'] ?? null)['name'] ?? null);

        if ($title === null || $artist === null) {
            return null;
        }

        $milliseconds = self::integer($track['duration'] ?? null) ?? 0;

        return new LastFmTrack($title, $artist, $milliseconds > 0 ? intdiv($milliseconds + 500, 1000) : null);
    }

    /**
     * @param  array<string, mixed>  $payload  a track.getInfo or artist.getInfo answer
     */
    public static function popularity(array $payload): ?Popularity
    {
        $subject = self::map($payload['track'] ?? $payload['artist'] ?? null);
        $stats = isset($subject['stats']) ? self::map($subject['stats']) : $subject;
        $listeners = self::integer($stats['listeners'] ?? null);

        return $listeners === null ? null : new Popularity($listeners, self::integer($stats['playcount'] ?? null));
    }

    /**
     * The strongest tags, at most MAX_TAGS, weight MIN_TAG_WEIGHT and over;
     * two spellings of one tag keep the first.
     *
     * @param  array<string, mixed>  $payload  a getTopTags answer
     * @return list<WeightedTag>
     */
    public static function tags(array $payload): array
    {
        $tags = [];

        foreach (self::rows(self::map($payload['toptags'] ?? null)['tag'] ?? null) as $tag) {
            $name = mb_strtolower(self::string($tag['name'] ?? null) ?? '');
            $weight = min(100, self::integer($tag['count'] ?? null) ?? 0);
            $slug = MusicText::tagSlug($name);

            if ($slug !== '' && $weight >= self::MIN_TAG_WEIGHT && ! isset($tags[$slug])) {
                $tags[$slug] = new WeightedTag($name, $weight, false);
            }
        }

        $tags = array_values($tags);
        usort($tags, fn (WeightedTag $a, WeightedTag $b): int => $b->weight <=> $a->weight);

        return array_slice($tags, 0, self::MAX_TAGS);
    }

    /**
     * @param  array<string, mixed>  $payload  a track.getSimilar answer
     * @return list<SimilarTrack>
     */
    public static function similarTracks(array $payload): array
    {
        $similar = [];

        foreach (self::rows(self::map($payload['similartracks'] ?? null)['track'] ?? null) as $track) {
            $title = self::string($track['name'] ?? null);
            $artist = self::string(self::map($track['artist'] ?? null)['name'] ?? null);

            if ($title !== null && $artist !== null) {
                $similar[] = new SimilarTrack($title, $artist, self::match($track['match'] ?? null));
            }
        }

        return $similar;
    }

    /**
     * @param  array<string, mixed>  $payload  an artist.getSimilar answer
     * @return list<SimilarArtist>
     */
    public static function similarArtists(array $payload): array
    {
        $similar = [];

        foreach (self::rows(self::map($payload['similarartists'] ?? null)['artist'] ?? null) as $artist) {
            $name = self::string($artist['name'] ?? null);

            if ($name !== null) {
                $similar[] = new SimilarArtist($name, self::match($artist['match'] ?? null));
            }
        }

        return $similar;
    }

    /**
     * @param  array<string, mixed>  $payload  a chart.getTopTracks or geo.getTopTracks answer
     * @return list<ChartPosition>
     */
    public static function chart(array $payload): array
    {
        $positions = [];

        foreach (self::rows(self::map($payload['tracks'] ?? null)['track'] ?? null) as $index => $track) {
            $title = self::string($track['name'] ?? null);
            $artist = self::string(self::map($track['artist'] ?? null)['name'] ?? null);

            if ($title !== null && $artist !== null) {
                $positions[] = new ChartPosition($index + 1, $title, $artist, self::integer($track['listeners'] ?? null), self::integer($track['playcount'] ?? null));
            }
        }

        return $positions;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * A list of objects; Last.fm sends a lone item as the object itself.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $value): array
    {
        $rows = [];
        $items = self::map($value);

        foreach (array_key_exists('name', $items) ? [$items] : $items as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function match(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
    }
}
