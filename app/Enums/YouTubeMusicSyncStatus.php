<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum YouTubeMusicSyncStatus: string
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Completed = 'completed';
    case Failed = 'failed';
}
