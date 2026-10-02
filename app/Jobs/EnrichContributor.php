<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DescribeContributor;
use App\Actions\ProjectContributorMetadata;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\Contributor;
use App\Models\Enrichment;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks one metadata source about one main artist, then projects its tags.
 * Unique per artist and source: several recordings name the same artist.
 */
final class EnrichContributor implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $contributorId,
        public readonly MetadataSource $source,
    ) {
        $this->onQueue('enrichment');
    }

    public function uniqueId(): string
    {
        return "{$this->contributorId}:{$this->source->value}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(days: 1);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(): void
    {
        $contributor = Contributor::query()->find($this->contributorId);

        if ($contributor === null) {
            return;
        }

        try {
            $status = resolve(DescribeContributor::class)->handle($contributor, $this->source);
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        if ($status instanceof EnrichmentStatus) {
            resolve(ProjectContributorMetadata::class)->handle($contributor);
        }
    }

    /**
     * Marks as failed what this source still owes about the contributor.
     */
    public function failed(Throwable $exception): void
    {
        foreach (DescribeContributor::endpoints($this->source) as $endpoint) {
            if (Enrichment::isDue(Enrichment::CONTRIBUTOR, $this->contributorId, $this->source, $endpoint)) {
                Enrichment::store(Enrichment::CONTRIBUTOR, $this->contributorId, $this->source, $endpoint, EnrichmentStatus::Failed, error: $exception->getMessage());
            }
        }
    }
}
