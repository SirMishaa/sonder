<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\PopularitySample;
use App\Models\SimilarContributor;
use App\Models\Tag;
use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\LastFm\LastFmMapper;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use Illuminate\Support\Facades\DB;

final readonly class ProjectContributorMetadata
{
    /**
     * Rebuilds the contributor's tags (MusicBrainz and Last.fm), similar
     * artists and popularity from its stored payloads.
     */
    public function handle(Contributor $contributor): void
    {
        $musicBrainz = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::MusicBrainz, 'artist');
        $lastFmInfo = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, Enrichment::INFO);
        $lastFmTags = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'top_tags');
        $lastFmSimilar = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'similar');

        $tags = [
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::MusicBrainz], $musicBrainz === null ? [] : MusicBrainzMapper::tags($musicBrainz)),
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::LastFm], $lastFmTags === null ? [] : LastFmMapper::tags($lastFmTags)),
        ];
        $similar = $lastFmSimilar === null ? [] : LastFmMapper::similarArtists($lastFmSimilar);
        $popularity = $lastFmInfo === null ? null : LastFmMapper::popularity($lastFmInfo);
        $measuredAt = Enrichment::fetchedAt(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, Enrichment::INFO);

        DB::transaction(function () use ($contributor, $tags, $similar, $popularity, $measuredAt): void {
            DB::table('contributor_tags')->where('contributor_id', $contributor->id)->delete();
            SimilarContributor::query()->where('contributor_id', $contributor->id)->where('source', MetadataSource::LastFm)->delete();

            foreach ($tags as ['tag' => $tag, 'source' => $source]) {
                DB::table('contributor_tags')->insertOrIgnore([
                    'contributor_id' => $contributor->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => $source->value,
                    'weight' => $tag->weight,
                ]);
            }

            foreach ($similar as $artist) {
                SimilarContributor::query()->create([
                    'contributor_id' => $contributor->id,
                    'name' => $artist->name,
                    'match' => $artist->match,
                    'source' => MetadataSource::LastFm,
                ]);
            }

            if ($popularity !== null) {
                $contributor->update(['lastfm_listeners' => $popularity->listeners, 'lastfm_playcount' => $popularity->playcount]);
            }

            if ($popularity !== null && $measuredAt !== null) {
                PopularitySample::record(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, $popularity->listeners, $popularity->playcount, $measuredAt);
            }
        });
    }
}
