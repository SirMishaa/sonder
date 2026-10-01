<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum EnrichmentStatus: string
{
    case Done = 'done';
    case NotFound = 'not_found';
    case Failed = 'failed';

    /**
     * When data in this state is worth asking for again.
     */
    public function nextAttemptAt(): CarbonImmutable
    {
        return match ($this) {
            self::Done => CarbonImmutable::now()->addDays(90),
            self::NotFound => CarbonImmutable::now()->addDays(30),
            self::Failed => CarbonImmutable::now()->addDay(),
        };
    }
}
