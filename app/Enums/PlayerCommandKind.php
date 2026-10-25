<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the extension sent: a single video or the tracks of a mix.
 */
#[TypeScript]
enum PlayerCommandKind: string
{
    case Video = 'video';
    case Mix = 'mix';
}
