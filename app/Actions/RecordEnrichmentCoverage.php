<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditType;
use App\Enums\EnrichmentStatus;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Support\Facades\DB;

final readonly class RecordEnrichmentCoverage
{
    public function __construct(private EnrichmentTelemetry $telemetry) {}

    /**
     * Measures how much of the library carries each kind of metadata, and
     * what waits to be fetched again.
     */
    public function handle(): void
    {
        $videos = Track::query()->whereNotNull('youtube_video_id')->distinct()->count('youtube_video_id');
        $byStatus = RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->get(['status'])
            ->countBy(fn (RecordingResolution $resolution): string => $resolution->status->value);
        $recordings = max(1, Recording::query()->count());

        $resolved = $byStatus->get(ResolutionStatus::Resolved->value, 0);
        $notFound = $byStatus->get(ResolutionStatus::NotFound->value, 0);
        $failed = $byStatus->get(ResolutionStatus::Failed->value, 0);
        $settled = $resolved + $notFound + $failed;

        $this->telemetry->coverage('resolved', $videos === 0 ? 0.0 : $resolved / $videos);
        $this->telemetry->coverage('credits', DB::table('recording_contributors')->where('credit_type', '!=', CreditType::Artist->value)->distinct()->count('recording_id') / $recordings);
        $this->telemetry->coverage('genres', DB::table('recording_tags')->join('tags', 'tags.id', '=', 'recording_tags.tag_id')->where('tags.is_genre', true)->distinct()->count('recording_id') / $recordings);

        $this->telemetry->backlog('failed', Enrichment::query()->where('status', EnrichmentStatus::Failed)->count() + $failed);
        $this->telemetry->backlog('not_found', $notFound);
        $this->telemetry->backlog('unresolved', max(0, $videos - $settled));
    }
}
