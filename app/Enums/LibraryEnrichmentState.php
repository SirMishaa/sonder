<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a library's metadata enrichment stands, as shown in the sidebar.
 */
#[TypeScript]
enum LibraryEnrichmentState: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Paused = 'paused';
    case Done = 'done';
    case Attention = 'attention';
}
