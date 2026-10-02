<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MetadataSource;
use App\Models\ChartSnapshot;
use App\Models\Recording;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\LastFm\LastFmMapper;
use App\Support\MusicText;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SnapshotChart
{
    /**
     * The charts followed, each with the Last.fm country it is asked for
     * (null worldwide).
     *
     * @var array<string, string|null>
     */
    public const array CHARTS = [
        'global' => null,
        'country:BE' => 'belgium',
        'country:FR' => 'france',
        'country:US' => 'united states',
    ];

    public function __construct(
        private LastFmGateway $lastFm,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * Takes today's snapshot of one chart and links its entries to the
     * recordings the library knows. Null when today's is already taken.
     */
    public function handle(string $chart): ?ChartSnapshot
    {
        if (! array_key_exists($chart, self::CHARTS)) {
            throw new InvalidArgumentException("Sonder does not follow the [{$chart}] chart.");
        }

        $today = now()->toDateString();
        $taken = ChartSnapshot::query()
            ->where('source', MetadataSource::LastFm)
            ->where('chart', $chart)
            ->whereDate('taken_on', $today)
            ->exists();

        if ($taken) {
            return null;
        }

        $positions = LastFmMapper::chart($this->lastFm->topTracks(self::CHARTS[$chart]));

        return DB::transaction(function () use ($chart, $today, $positions): ChartSnapshot {
            $snapshot = ChartSnapshot::query()->create(['source' => MetadataSource::LastFm, 'chart' => $chart, 'taken_on' => $today]);
            $linked = 0;

            foreach ($positions as $position) {
                $recordingId = Recording::query()
                    ->where('match_title', MusicText::matchTitle($position->title))
                    ->where('match_artist', MusicText::matchArtist($position->artist))
                    ->value('id');

                $snapshot->entries()->create([
                    'rank' => $position->rank,
                    'title' => $position->title,
                    'artist_name' => $position->artist,
                    'listeners' => $position->listeners,
                    'playcount' => $position->playcount,
                    'recording_id' => is_string($recordingId) ? $recordingId : null,
                ]);

                $linked += is_string($recordingId) ? 1 : 0;
            }

            $this->telemetry->chartEntries($chart, $linked, count($positions) - $linked);

            return $snapshot;
        });
    }
}
