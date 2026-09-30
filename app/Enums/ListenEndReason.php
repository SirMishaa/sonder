<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a listen was left.
 */
#[TypeScript]
enum ListenEndReason: string
{
    case Ended = 'ended';
    case Skipped = 'skipped';
    case Previous = 'previous';
    case Jumped = 'jumped';
    case Replaced = 'replaced';
    case Picked = 'picked';
    case Error = 'error';
    case Abandoned = 'abandoned';
}
