<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolutionMethod: string
{
    case CreditsFm = 'credits_fm';
    case MusicBrainzSearch = 'musicbrainz_search';
    case LastFm = 'lastfm';
}
