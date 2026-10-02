<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditType;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Models\RecordingResolution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class TrackGenres
{
    /**
     * The strongest genres of each YouTube Music video, weights summed
     * across sources: the recording's own, or its main artist's when it has
     * none. Videos without a recording or a genre are left out.
     *
     * @param  list<string>  $videoIds
     * @return array<string, list<string>>
     */
    public function handle(array $videoIds, int $limit = 2): array
    {
        if ($videoIds === []) {
            return [];
        }

        $recordings = [];

        RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->where('status', ResolutionStatus::Resolved)
            ->whereIn('external_id', $videoIds)
            ->whereNotNull('recording_id')
            ->get(['external_id', 'recording_id'])
            ->each(function (RecordingResolution $resolution) use (&$recordings): void {
                if ($resolution->recording_id !== null) {
                    $recordings[$resolution->external_id] = $resolution->recording_id;
                }
            });

        $own = $this->strongest(DB::table('recording_tags')
            ->join('tags', 'tags.id', '=', 'recording_tags.tag_id')
            ->whereIn('recording_tags.recording_id', array_values($recordings))
            ->selectRaw('recording_tags.recording_id, tags.name, sum(recording_tags.weight) as weight')
            ->groupBy('recording_tags.recording_id', 'tags.name'), $limit);

        $byArtist = $this->strongest(DB::table('recording_contributors')
            ->join('contributor_tags', 'contributor_tags.contributor_id', '=', 'recording_contributors.contributor_id')
            ->join('tags', 'tags.id', '=', 'contributor_tags.tag_id')
            ->whereIn('recording_contributors.recording_id', array_values($recordings))
            ->where('recording_contributors.credit_type', CreditType::Artist->value)
            ->selectRaw('recording_contributors.recording_id, tags.name, sum(contributor_tags.weight) as weight')
            ->groupBy('recording_contributors.recording_id', 'tags.name'), $limit);

        $genres = [];

        foreach ($recordings as $videoId => $recordingId) {
            $strongest = $own[$recordingId] ?? $byArtist[$recordingId] ?? [];

            if ($strongest !== []) {
                $genres[$videoId] = $strongest;
            }
        }

        return $genres;
    }

    /**
     * @return array<string, list<string>> recording id => genre names, strongest first
     */
    private function strongest(Builder $query, int $limit): array
    {
        $strongest = [];

        $query->where('tags.is_genre', true)
            ->orderByDesc('weight')
            ->orderBy('tags.name')
            ->get()
            ->each(function (object $row) use (&$strongest, $limit): void {
                $recordingId = $row->recording_id ?? null;
                $name = $row->name ?? null;

                if (is_string($recordingId) && is_string($name) && count($strongest[$recordingId] ?? []) < $limit) {
                    $strongest[$recordingId][] = $name;
                }
            });

        return $strongest;
    }
}
