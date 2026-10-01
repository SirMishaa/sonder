<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a playable source is: the bare recording, or a music video of it.
 */
enum SourceKind: string
{
    case Audio = 'audio';
    case Video = 'video';
}
