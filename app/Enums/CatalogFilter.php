<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of result a catalog search is narrowed to.
 */
enum CatalogFilter: string
{
    case Tracks = 'tracks';
    case Albums = 'albums';
    case Artists = 'artists';
    case Playlists = 'playlists';
}
