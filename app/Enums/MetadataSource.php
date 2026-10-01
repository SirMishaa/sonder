<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A service Sonder reads metadata from. Not a streaming provider.
 */
enum MetadataSource: string
{
    case CreditsFm = 'credits_fm';
    case MusicBrainz = 'musicbrainz';
    case LastFm = 'lastfm';
    case YouTubeMusic = 'youtube_music';
}
