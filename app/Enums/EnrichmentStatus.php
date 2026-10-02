<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Enrichment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum EnrichmentStatus: string
{
    case Done = 'done';
    case NotFound = 'not_found';
    case Failed = 'failed';

    /**
     * When data in this state is worth asking for again. Popularity readings
     * (`info`) go stale after a week, everything else a source knows after
     * 90 days.
     */
    public function nextAttemptAt(string $endpoint = '', ?CarbonInterface $from = null): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from ?? CarbonImmutable::now());

        return match ($this) {
            self::Done => $from->addDays($endpoint === Enrichment::INFO ? 7 : 90),
            self::NotFound => $from->addDays(30),
            self::Failed => $from->addDay(),
        };
    }
}
