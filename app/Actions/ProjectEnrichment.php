<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditType;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\PopularitySample;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\SimilarRecording;
use App\Models\Tag;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\Credit;
use App\Services\Metadata\Data\LastFmTrack;
use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\LastFm\LastFmMapper;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use App\Support\MusicText;
use Illuminate\Support\Facades\DB;

final readonly class ProjectEnrichment
{
    /**
     * Rebuilds the recording's credits, tags, similar tracks and popularity
     * from every stored payload, fills identity fields it lacks, and returns
     * its main artists. Last.fm names the main artist only when MusicBrainz
     * credits none. Safe to run again at any time.
     *
     * @return list<Contributor>
     */
    public function handle(Recording $recording): array
    {
        $creditsFm = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc');
        $musicBrainz = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording');
        $lastFmInfo = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO);
        $lastFmTags = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, 'top_tags');
        $lastFmSimilar = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, 'similar');

        $credits = [];

        foreach ($creditsFm === null ? [] : CreditsFmMapper::credits($creditsFm) as $credit) {
            $credits[] = ['credit' => $credit, 'source' => MetadataSource::CreditsFm];
        }

        $registry = $musicBrainz === null ? null : MusicBrainzMapper::recording($musicBrainz);

        foreach ($registry === null ? [] : $registry->artists as $artist) {
            $credits[] = ['credit' => new Credit($artist['name'], CreditType::Artist, '', $artist['mbid'], null), 'source' => MetadataSource::MusicBrainz];
        }

        $lastFmTrack = $lastFmInfo === null ? null : LastFmMapper::track($lastFmInfo);

        if (($registry === null || $registry->artists === []) && $lastFmTrack instanceof LastFmTrack) {
            $credits[] = ['credit' => new Credit($lastFmTrack->artist, CreditType::Artist, '', null, null), 'source' => MetadataSource::LastFm];
        }

        $tags = [
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::MusicBrainz], $musicBrainz === null ? [] : MusicBrainzMapper::tags($musicBrainz)),
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::LastFm], $lastFmTags === null ? [] : LastFmMapper::tags($lastFmTags)),
        ];
        $similar = $lastFmSimilar === null ? [] : LastFmMapper::similarTracks($lastFmSimilar);
        $popularity = $lastFmInfo === null ? null : LastFmMapper::popularity($lastFmInfo);
        $measuredAt = Enrichment::fetchedAt(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO);
        $detail = $creditsFm === null ? null : CreditsFmMapper::detail($creditsFm);

        return DB::transaction(function () use ($recording, $credits, $tags, $similar, $popularity, $measuredAt, $detail, $registry): array {
            $recording->update([
                ...array_filter([
                    'iswc' => $recording->iswc ?? $detail?->iswc,
                    'release_date' => $recording->release_date ?? $detail->releaseDate ?? $registry?->firstReleaseDate,
                    'lastfm_listeners' => $popularity?->listeners,
                    'lastfm_playcount' => $popularity?->playcount,
                ], fn (mixed $value): bool => $value !== null),
                'projected_at' => now(),
            ]);

            RecordingContributor::query()->where('recording_id', $recording->id)->delete();
            DB::table('recording_tags')->where('recording_id', $recording->id)->delete();
            SimilarRecording::query()->where('recording_id', $recording->id)->where('source', MetadataSource::LastFm)->delete();

            $mainArtists = [];

            foreach ($credits as ['credit' => $credit, 'source' => $source]) {
                $contributor = $this->contributorFor($credit);

                RecordingContributor::query()->createOrFirst([
                    'recording_id' => $recording->id,
                    'contributor_id' => $contributor->id,
                    'credit_type' => $credit->type,
                    'role' => $credit->role,
                    'source' => $source,
                ], ['credit_attributes' => $credit->attributes]);

                if ($credit->type === CreditType::Artist) {
                    $mainArtists[$contributor->id] = $contributor;
                }
            }

            foreach ($tags as ['tag' => $tag, 'source' => $source]) {
                DB::table('recording_tags')->insertOrIgnore([
                    'recording_id' => $recording->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => $source->value,
                    'weight' => $tag->weight,
                ]);
            }

            foreach ($similar as $track) {
                SimilarRecording::query()->create([
                    'recording_id' => $recording->id,
                    'title' => $track->title,
                    'artist_name' => $track->artist,
                    'match' => $track->match,
                    'source' => MetadataSource::LastFm,
                ]);
            }

            if ($popularity !== null && $measuredAt !== null) {
                PopularitySample::record(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, $popularity->listeners, $popularity->playcount, $measuredAt);
            }

            return array_values($mainArtists);
        });
    }

    /**
     * By MBID, then IPI, then an existing name-only contributor with the
     * same normalised name; created otherwise.
     */
    private function contributorFor(Credit $credit): Contributor
    {
        $values = ['name' => $credit->name, 'normalized_name' => MusicText::normalize($credit->name)];

        if ($credit->mbid !== null) {
            return Contributor::query()->createOrFirst(['mbid' => $credit->mbid], [...$values, 'ipi' => $credit->ipi]);
        }

        if ($credit->ipi !== null) {
            return Contributor::query()->createOrFirst(['ipi' => $credit->ipi], $values);
        }

        return Contributor::query()
            ->whereNull('mbid')
            ->whereNull('ipi')
            ->where('normalized_name', $values['normalized_name'])
            ->first()
            ?? Contributor::query()->create($values);
    }
}
