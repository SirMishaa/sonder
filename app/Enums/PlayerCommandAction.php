<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a command sent from the browser extension does to the queue.
 */
#[TypeScript]
enum PlayerCommandAction: string
{
    case PlayNow = 'play_now';
    case PlayNext = 'play_next';
    case Enqueue = 'enqueue';
}
