<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Metadata\Data\TrackQuery;
use Illuminate\Support\Str;

/**
 * Normalises titles and artist names the way providers write them, for
 * querying registries and comparing their answers. Plan 2's matching reuses
 * it for `match_title` / `match_artist`.
 */
final class MusicText
{
    private const string NOISE = '/\s*(?:[\(\[]\s*(?:official\s*)?(?:music\s*)?(?:video|audio|lyrics?(?:\s*video)?|visuali[sz]er|clip(?:\s*officiel)?|hd|hq|4k)\s*[\)\]]|\bofficial\s+(?:music\s+)?(?:video|audio)\b)/iu';

    private const string FEATURING = '/\s*[\(\[]\s*(?:feat\.?|ft\.?|featuring)\s[^\)\]]*[\)\]]/iu';

    private const string ARTIST_SEPARATOR = '/\s*(?:,|&|\bx\b|\b(?:feat|ft)\b\.?)\s*/iu';

    public static function normalize(string $text): string
    {
        $ascii = Str::lower(Str::ascii(str_replace('&', ' and ', $text)));
        $words = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return mb_trim(preg_replace('/\s+/', ' ', $words) ?? $words);
    }

    public static function cleanTitle(string $title): string
    {
        $clean = preg_replace([self::NOISE, self::FEATURING], '', $title) ?? $title;

        return mb_trim(preg_replace('/\s+/', ' ', $clean) ?? $clean, " \t-");
    }

    public static function firstArtist(string $artists): string
    {
        $withoutTopic = preg_replace('/\s+-\s+topic$/i', '', mb_trim($artists)) ?? $artists;
        $parts = preg_split(self::ARTIST_SEPARATOR, $withoutTopic);

        return mb_trim(is_array($parts) && $parts[0] !== '' ? $parts[0] : $withoutTopic);
    }

    /**
     * The readings worth asking about, most likely first. "Left - Right"
     * is read as artist and title unless the left part already is the
     * artist; the literal reading is kept as a fallback.
     *
     * @return list<TrackQuery>
     */
    public static function queries(string $title, string $artists): array
    {
        $clean = self::cleanTitle($title);
        $artist = self::firstArtist($artists);
        $parts = explode(' - ', $clean, 2);

        if (count($parts) < 2) {
            return [new TrackQuery($clean, $artist)];
        }

        [$left, $right] = $parts;

        if (self::sameArtist($left, $artist)) {
            return [new TrackQuery($right, $artist)];
        }

        return [
            new TrackQuery($right, self::firstArtist($left)),
            new TrackQuery($clean, $artist),
        ];
    }

    public static function sameArtist(string $a, string $b): bool
    {
        $left = self::withoutArticle(self::normalize($a));

        return $left !== '' && $left === self::withoutArticle(self::normalize($b));
    }

    public static function sameTitle(string $a, string $b): bool
    {
        $left = self::normalize(self::cleanTitle($a));

        return $left !== '' && $left === self::normalize(self::cleanTitle($b));
    }

    public static function tagSlug(string $name): string
    {
        return self::normalize($name);
    }

    private static function withoutArticle(string $normalized): string
    {
        return preg_replace('/^the /', '', $normalized) ?? $normalized;
    }
}
