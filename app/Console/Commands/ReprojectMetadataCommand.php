<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProjectRecordingMetadata;
use App\Models\Recording;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:reproject')]
#[Description('Rebuild credits and tags from the stored raw metadata, without calling any service')]
final class ReprojectMetadataCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        Recording::query()->lazyById()->each(function (Recording $recording) use (&$count): void {
            ProjectRecordingMetadata::dispatch($recording->id);
            $count++;
        });

        $this->components->info("Queued {$count} recordings for projection.");

        return self::SUCCESS;
    }
}
