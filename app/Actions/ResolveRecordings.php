<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Models\Enrichment;
use App\Models\PopularitySample;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\LastFmTrack;
use App\Services\Metadata\Data\RegistryRecording;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\Data\TrackToResolve;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\LastFm\LastFmMapper;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use App\Support\MusicText;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class ResolveRecordings
{
    public const int MAX_TRACKS = 25;

    /**
     * credits.fm answers a pair in over a second, so it is asked a few at a
     * time to keep each call well inside the HTTP timeout.
     */
    private const int CREDITS_FM_BATCH = 6;

    private const int DURATION_TOLERANCE_SECONDS = 5;

    private const string VERSION_MARKERS = '/\b(live|instrumental|karaoke|acoustic|demo)\b/i';

    public function __construct(
        private CreditsFmGateway $creditsFm,
        private MusicBrainzGateway $musicBrainz,
        private LastFmGateway $lastFm,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * Matches provider tracks to registry recordings and records every
     * outcome. Rate limits propagate so the caller can retry the batch:
     * tracks already resolved keep their resolution, and credits.fm's
     * answers are kept a day so a retry does not ask again.
     *
     * A run first resolves the tracks credits.fm already answered (cheap:
     * MusicBrainz answers in a fraction of a second), then asks credits.fm
     * about the others a few at a time with the time left, resolving each
     * answer as it comes. A run that got nothing done still asks once, so
     * every run moves forward. A track the registries miss is looked up on
     * Last.fm last, and becomes a recording known by name.
     *
     * @param  list<TrackToResolve>  $tracks  at most MAX_TRACKS
     * @param  CarbonInterface|null  $until  stops between tracks and calls once past it; the rest stays unresolved
     * @return list<Recording>
     */
    public function handle(array $tracks, ?CarbonInterface $until = null): array
    {
        $resolved = [];
        $toAsk = [];
        $worked = false;
        $pastDeadline = fn (): bool => $until !== null && now()->greaterThanOrEqualTo($until);

        foreach ($tracks as $index => $track) {
            $stored = $this->storedReadings($track);

            if ($stored === null) {
                $toAsk[$index] = MusicText::queries($track->title, $track->artists);

                continue;
            }

            if ($worked && $pastDeadline()) {
                return array_values($resolved);
            }

            $this->keep($resolved, $this->resolve($track, $stored));
            $worked = true;
        }

        foreach ($this->creditsFmBatches($toAsk) as $batch) {
            if ($worked && $pastDeadline()) {
                break;
            }

            foreach ($this->askCreditsFm($tracks, $batch) as $index => $candidates) {
                if ($worked && $pastDeadline()) {
                    break 2;
                }

                $this->keep($resolved, $this->resolve($tracks[$index], $candidates));
                $worked = true;
            }
        }

        return array_values($resolved);
    }

    /**
     * @param  array<string, Recording>  $resolved
     */
    private function keep(array &$resolved, ?Recording $recording): void
    {
        if ($recording instanceof Recording) {
            $resolved[$recording->id] = $recording;
        }
    }

    /**
     * Groups the tracks to ask so a call carries at most CREDITS_FM_BATCH
     * readings, a track's readings staying together.
     *
     * @param  array<int, list<TrackQuery>>  $toAsk
     * @return list<array<int, list<TrackQuery>>>
     */
    private function creditsFmBatches(array $toAsk): array
    {
        $batches = [];
        $batch = [];
        $size = 0;

        foreach ($toAsk as $index => $queries) {
            if ($batch !== [] && $size + count($queries) > self::CREDITS_FM_BATCH) {
                $batches[] = $batch;
                $batch = [];
                $size = 0;
            }

            $batch[$index] = $queries;
            $size += count($queries);
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Asks credits.fm one batch and keeps each track's readings a day.
     *
     * @param  list<TrackToResolve>  $tracks
     * @param  array<int, list<TrackQuery>>  $batch
     * @return array<int, list<array{query: TrackQuery, isrc: string|null}>>
     */
    private function askCreditsFm(array $tracks, array $batch): array
    {
        $isrcs = $this->creditsFm->resolveBatch(array_merge(...array_values($batch)));
        $position = 0;
        $candidates = [];

        foreach ($batch as $index => $queries) {
            foreach ($queries as $query) {
                $candidates[$index][] = ['query' => $query, 'isrc' => $isrcs[$position++] ?? null];
            }

            Enrichment::store(Enrichment::SOURCE, $tracks[$index]->key(), MetadataSource::CreditsFm, 'resolve', EnrichmentStatus::Done, [
                'readings' => array_map(fn (array $candidate): array => [
                    'title' => $candidate['query']->title,
                    'artist' => $candidate['query']->artist,
                    'isrc' => $candidate['isrc'],
                ], $candidates[$index]),
            ]);
        }

        return $candidates;
    }

    /**
     * @return list<array{query: TrackQuery, isrc: string|null}>|null
     */
    private function storedReadings(TrackToResolve $track): ?array
    {
        $stored = Enrichment::query()
            ->where('subject_type', Enrichment::SOURCE)
            ->where('subject_key', $track->key())
            ->where('source', MetadataSource::CreditsFm)
            ->where('endpoint', 'resolve')
            ->where('fetched_at', '>=', now()->subDay())
            ->first()
            ?->payload;

        $readings = is_array($stored['readings'] ?? null) ? $stored['readings'] : [];
        $candidates = [];

        foreach ($readings as $reading) {
            if (is_array($reading) && is_string($reading['title'] ?? null) && is_string($reading['artist'] ?? null)) {
                $isrc = $reading['isrc'] ?? null;
                $candidates[] = ['query' => new TrackQuery($reading['title'], $reading['artist']), 'isrc' => is_string($isrc) ? $isrc : null];
            }
        }

        return $candidates === [] ? null : $candidates;
    }

    /**
     * @param  list<array{query: TrackQuery, isrc: string|null}>  $candidates
     */
    private function resolve(TrackToResolve $track, array $candidates): ?Recording
    {
        foreach ($candidates as $candidate) {
            if ($candidate['isrc'] === null) {
                continue;
            }

            $confirmed = $this->confirmedByMusicBrainz($track, $candidate['query'], $candidate['isrc']);

            if ($confirmed instanceof RegistryRecording) {
                return $this->record($track, $candidate['query'], ResolutionMethod::CreditsFm, 1.0, $confirmed, $candidate['isrc']);
            }

            if ($this->confirmedByCreditsFm($track, $candidate['query'], $candidate['isrc'])) {
                return $this->record($track, $candidate['query'], ResolutionMethod::CreditsFm, 0.6, null, $candidate['isrc']);
            }
        }

        foreach ($candidates as $candidate) {
            $found = $this->searchMusicBrainz($track, $candidate['query']);

            if ($found instanceof RegistryRecording) {
                return $this->record($track, $candidate['query'], ResolutionMethod::MusicBrainzSearch, 0.9, $found, $found->isrcs[0] ?? null);
            }
        }

        if ($this->lastFm->enabled()) {
            foreach ($candidates as $candidate) {
                $known = $this->knownToLastFm($track, $candidate['query']);

                if ($known !== null) {
                    return $this->recordByName($track, $candidate['query'], $known['track'], $known['payload']);
                }
            }
        }

        $this->recordMiss($track, $candidates[0]['query'] ?? new TrackQuery($track->title, $track->artists));

        return null;
    }

    private function confirmedByMusicBrainz(TrackToResolve $track, TrackQuery $query, string $isrc): ?RegistryRecording
    {
        $payload = $this->ask($track, MetadataSource::MusicBrainz, "isrc:{$isrc}", fn (): ?array => $this->musicBrainz->isrc($isrc));

        if ($payload === null) {
            return null;
        }

        foreach (MusicBrainzMapper::recordings($payload) as $recording) {
            $lengthsCompared = $track->durationSeconds !== null && $recording->durationSeconds !== null;

            if ($this->sameDuration($track->durationSeconds, $recording->durationSeconds)
                && $this->creditsArtist($recording, $query->artist, $track->artists)
                && ($lengthsCompared || MusicText::sameTitle($recording->title, $query->title))) {
                return $recording;
            }
        }

        return null;
    }

    /**
     * One answer of a source about this track, reused when it is less than
     * a day old: a job released halfway through does not ask again.
     *
     * @param  callable(): (array<string, mixed>|null)  $fetch
     * @return array<string, mixed>|null
     */
    private function ask(TrackToResolve $track, MetadataSource $source, string $endpoint, callable $fetch): ?array
    {
        $known = Enrichment::query()
            ->where('subject_type', Enrichment::SOURCE)
            ->where('subject_key', $track->key())
            ->where('source', $source)
            ->where('endpoint', $endpoint)
            ->whereIn('status', [EnrichmentStatus::Done, EnrichmentStatus::NotFound])
            ->where('fetched_at', '>=', now()->subDay())
            ->first();

        if ($known instanceof Enrichment) {
            return $known->payload;
        }

        $payload = $fetch();
        Enrichment::store(Enrichment::SOURCE, $track->key(), $source, $endpoint, $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done, $payload);

        return $payload;
    }

    private function confirmedByCreditsFm(TrackToResolve $track, TrackQuery $query, string $isrc): bool
    {
        $payload = $this->ask($track, MetadataSource::CreditsFm, "isrc:{$isrc}", fn (): ?array => $this->creditsFm->isrc($isrc));

        if ($payload === null) {
            return false;
        }

        $detail = CreditsFmMapper::detail($payload);

        return MusicText::sameTitle($detail->title, $query->title)
            && array_any($detail->artists, fn (string $artist): bool => MusicText::sameArtist($artist, $query->artist) || MusicText::sameArtist($artist, $track->artists));
    }

    private function searchMusicBrainz(TrackToResolve $track, TrackQuery $query): ?RegistryRecording
    {
        $payload = $this->ask($track, MetadataSource::MusicBrainz, "search:{$query->artist}|{$query->title}", fn (): array => $this->musicBrainz->searchRecordings($query)) ?? [];
        $sameSong = array_values(array_filter(
            MusicBrainzMapper::recordings($payload),
            fn (RegistryRecording $recording): bool => $this->creditsArtist($recording, $query->artist, $track->artists)
                && MusicText::sameTitle($recording->title, $query->title),
        ));

        if ($track->durationSeconds === null) {
            // Every exact title and artist match scores 100: without a length
            // to compare, only a single such match is unambiguous.
            $exact = array_values(array_filter($sameSong, fn (RegistryRecording $recording): bool => $recording->score === 100));

            return count($exact) === 1 && ! $this->namesAnotherVersion($exact[0]->disambiguation, $track->title) ? $exact[0] : null;
        }

        foreach ($sameSong as $recording) {
            if (($recording->score ?? 0) >= 90
                && $recording->durationSeconds !== null
                && $this->sameDuration($track->durationSeconds, $recording->durationSeconds)
                && ! $this->namesAnotherVersion($recording->disambiguation, $track->title)) {
                return $recording;
            }
        }

        return null;
    }

    /**
     * A "live" or "instrumental" recording is another version unless the
     * source title says the same.
     */
    private function namesAnotherVersion(?string $disambiguation, string $sourceTitle): bool
    {
        if ($disambiguation === null || preg_match_all(self::VERSION_MARKERS, $disambiguation, $markers) < 1) {
            return false;
        }

        return array_any($markers[1], fn (string $marker): bool => preg_match('/\b'.preg_quote($marker, '/').'\b/i', $sourceTitle) !== 1);
    }

    private function sameDuration(?int $source, ?int $registry): bool
    {
        return $source === null || $registry === null || abs($source - $registry) <= self::DURATION_TOLERANCE_SECONDS;
    }

    private function creditsArtist(RegistryRecording $recording, string $artist, string $sourceArtists): bool
    {
        return array_any($recording->artists, fn (array $credited): bool => MusicText::sameArtist($credited['name'], $artist)
            || MusicText::sameArtist($credited['name'], $sourceArtists));
    }

    private function record(TrackToResolve $track, TrackQuery $query, ResolutionMethod $method, float $confidence, ?RegistryRecording $registry, ?string $isrc): Recording
    {
        $current = RecordingResolution::query()
            ->where('provider', $track->provider)
            ->where('external_id', $track->externalId)
            ->first()
            ?->recording;
        $nameOnly = $current instanceof Recording && $current->isNameOnly() ? $current : null;

        $recording = $this->recordingFor($registry, $isrc, $query, $track->durationSeconds, $nameOnly);
        $this->resolveTo($track, $query, $method, $confidence, $recording);

        if ($nameOnly instanceof Recording && $nameOnly->id !== $recording->id && $nameOnly->resolutions()->doesntExist()) {
            $this->dropNameOnly($nameOnly, $recording);
        }

        return $recording;
    }

    /**
     * Deletes a name-only recording another one replaced: its popularity
     * history moves to the replacement, its stored answers are forgotten.
     */
    private function dropNameOnly(Recording $nameOnly, Recording $replacement): void
    {
        DB::transaction(function () use ($nameOnly, $replacement): void {
            PopularitySample::query()
                ->where('subject_type', Enrichment::RECORDING)
                ->where('subject_id', $nameOnly->id)
                ->update(['subject_id' => $replacement->id]);
            Enrichment::query()->where('subject_type', Enrichment::RECORDING)->where('subject_key', $nameOnly->id)->delete();
            $nameOnly->delete();
        });
    }

    private function resolveTo(TrackToResolve $track, TrackQuery $query, ResolutionMethod $method, float $confidence, Recording $recording): void
    {
        RecordingResolution::query()->updateOrCreate(
            ['provider' => $track->provider, 'external_id' => $track->externalId],
            [
                'recording_id' => $recording->id,
                'status' => ResolutionStatus::Resolved,
                'method' => $method,
                'confidence' => $confidence,
                'query_title' => $query->title,
                'query_artist' => $query->artist,
                'attempts' => 0,
                'resolved_at' => now(),
                'next_attempt_at' => EnrichmentStatus::Done->nextAttemptAt(),
            ],
        );

        $this->telemetry->resolution(ResolutionStatus::Resolved, $method, $confidence);
    }

    /**
     * Last.fm's corrected track when it is the same song: same title, same
     * artist, and lengths within the tolerance when both are known.
     *
     * @return array{track: LastFmTrack, payload: array<string, mixed>}|null
     */
    private function knownToLastFm(TrackToResolve $track, TrackQuery $query): ?array
    {
        try {
            $payload = $this->ask($track, MetadataSource::LastFm, "track_info:{$query->artist}|{$query->title}", fn (): ?array => $this->lastFm->trackInfo($query));
        } catch (MetadataSourceUnavailable) {
            // Last.fm is the optional last step: an outage leaves a miss to retry, not a failed batch.
            return null;
        }

        $known = $payload === null ? null : LastFmMapper::track($payload);

        if ($payload === null
            || ! $known instanceof LastFmTrack
            || ! MusicText::sameTitle($known->title, $query->title)
            || ! (MusicText::sameArtist($known->artist, $query->artist) || MusicText::sameArtist($known->artist, $track->artists))
            || ! $this->sameDuration($track->durationSeconds, $known->durationSeconds)) {
            return null;
        }

        return ['track' => $known, 'payload' => $payload];
    }

    /**
     * Resolves to the recording known by Last.fm's names, created when new,
     * and keeps Last.fm's answer as its first popularity reading.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordByName(TrackToResolve $track, TrackQuery $query, LastFmTrack $known, array $payload): Recording
    {
        $recording = Recording::findByName($known->title, $known->artist)
            ?? Recording::query()->create([
                'title' => $known->title,
                'artist_name' => $known->artist,
                'duration_seconds' => $known->durationSeconds ?? $track->durationSeconds,
            ]);

        $this->resolveTo($track, $query, ResolutionMethod::LastFm, 0.5, $recording);
        Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, $payload);

        return $recording;
    }

    private function recordMiss(TrackToResolve $track, TrackQuery $query): void
    {
        RecordingResolution::query()->updateOrCreate(
            ['provider' => $track->provider, 'external_id' => $track->externalId],
            [
                'recording_id' => null,
                'status' => ResolutionStatus::NotFound,
                'method' => null,
                'confidence' => null,
                'query_title' => $query->title,
                'query_artist' => $query->artist,
                'resolved_at' => null,
                'next_attempt_at' => EnrichmentStatus::NotFound->nextAttemptAt(),
            ],
        );

        $this->telemetry->resolution(ResolutionStatus::NotFound, null, null);
    }

    /**
     * By MusicBrainz id first; otherwise adopts a recording known only by
     * this ISRC, or the track's name-only recording; otherwise creates one.
     */
    private function recordingFor(?RegistryRecording $registry, ?string $isrc, TrackQuery $query, ?int $duration, ?Recording $nameOnly): Recording
    {
        $values = [
            'title' => $registry->title ?? $query->title,
            'artist_name' => $registry->artists[0]['name'] ?? $query->artist,
            'duration_seconds' => $registry->durationSeconds ?? $duration,
        ];

        $isrcOnly = fn (): ?Recording => $isrc === null ? null : Recording::query()->whereNull('mbid')->where('isrc', $isrc)->first();

        if (! $registry instanceof RegistryRecording) {
            return $isrcOnly()
                ?? $this->adopt($nameOnly, [...$values, 'isrc' => $isrc])
                ?? Recording::query()->create([...$values, 'isrc' => $isrc]);
        }

        $byMbid = Recording::query()->where('mbid', $registry->mbid)->first();

        if ($byMbid instanceof Recording) {
            if ($byMbid->isrc === null && $isrc !== null) {
                $byMbid->update(['isrc' => $isrc]);
            }

            return $byMbid;
        }

        $adopted = $isrcOnly();

        if ($adopted instanceof Recording) {
            $adopted->update(['mbid' => $registry->mbid, ...$values]);

            return $adopted;
        }

        return $this->adopt($nameOnly, [...$values, 'mbid' => $registry->mbid, 'isrc' => $isrc])
            ?? Recording::query()->createOrFirst(['mbid' => $registry->mbid], [...$values, 'isrc' => $isrc]);
    }

    /**
     * Gives the track's name-only recording the identity just found, when
     * there is one.
     *
     * @param  array<string, int|string|null>  $values
     */
    private function adopt(?Recording $nameOnly, array $values): ?Recording
    {
        $nameOnly?->update($values);

        return $nameOnly;
    }
}
