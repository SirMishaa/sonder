<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a listen started.
 */
#[TypeScript]
enum ListenOrigin: string
{
    case Playlist = 'playlist';
    case Search = 'search';
    case Suggestion = 'suggestion';
    case Queue = 'queue';
    case Autoplay = 'autoplay';
}
