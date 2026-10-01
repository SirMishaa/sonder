<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an artist takes part in a track.
 */
enum ArtistRole: string
{
    case Main = 'main';
    case Featured = 'featured';
}
