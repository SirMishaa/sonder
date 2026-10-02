<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SnapshotChart;
use App\Jobs\TakeChartSnapshot;
use App\Services\Metadata\LastFm\LastFmGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:charts')]
#[Description('Queue today\'s snapshot of every chart followed (Last.fm)')]
final class TakeChartSnapshotsCommand extends Command
{
    public function handle(LastFmGateway $lastFm): int
    {
        if (! $lastFm->enabled()) {
            $this->components->warn('No Last.fm API key: no chart taken.');

            return self::SUCCESS;
        }

        foreach (array_keys(SnapshotChart::CHARTS) as $chart) {
            TakeChartSnapshot::dispatch($chart);
        }

        $this->components->info('Queued '.count(SnapshotChart::CHARTS).' chart snapshots.');

        return self::SUCCESS;
    }
}
