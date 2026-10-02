<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditType;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\SimilarRecording;
use App\Models\Track;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

        $lastFmTagged = Recording::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereExists(fn (QueryBuilder $tags): QueryBuilder => $tags->select(DB::raw(1))
                    ->from('recording_tags')
                    ->whereColumn('recording_tags.recording_id', 'recordings.id')
                    ->where('recording_tags.source', MetadataSource::LastFm->value))
                ->orWhereExists(fn (QueryBuilder $artists): QueryBuilder => $artists->select(DB::raw(1))
                    ->from('recording_contributors')
                    ->join('contributor_tags', 'contributor_tags.contributor_id', '=', 'recording_contributors.contributor_id')
                    ->whereColumn('recording_contributors.recording_id', 'recordings.id')
                    ->where('recording_contributors.credit_type', CreditType::Artist->value)
                    ->where('contributor_tags.source', MetadataSource::LastFm->value)))
            ->count();

        $this->telemetry->coverage('lastfm_tags', $lastFmTagged / $recordings);
        $this->telemetry->coverage('similar', SimilarRecording::query()->distinct()->count('recording_id') / $recordings);
        $this->telemetry->coverage('popularity', Recording::query()->whereNotNull('lastfm_listeners')->count() / $recordings);

        $this->telemetry->backlog('failed', Enrichment::query()->where('status', EnrichmentStatus::Failed)->count() + $failed);
        $this->telemetry->backlog('not_found', $notFound);
        $this->telemetry->backlog('unresolved', max(0, $videos - $settled));
    }
}
