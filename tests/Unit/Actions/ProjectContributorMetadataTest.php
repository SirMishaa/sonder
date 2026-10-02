<?php

declare(strict_types=1);

use App\Actions\ProjectContributorMetadata;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\PopularitySample;
use App\Models\SimilarContributor;
use Illuminate\Support\Facades\DB;

it('projects the artist tags and rebuilds them on refresh', function (): void {
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    Enrichment::store(Enrichment::CONTRIBUTOR, $muse->id, MetadataSource::MusicBrainz, 'artist', EnrichmentStatus::Done, metadataFixture('musicbrainz-artist'));

    resolve(ProjectContributorMetadata::class)->handle($muse);
    $count = DB::table('contributor_tags')->count();
    resolve(ProjectContributorMetadata::class)->handle($muse);

    $weight = DB::table('contributor_tags')
        ->join('tags', 'tags.id', '=', 'contributor_tags.tag_id')
        ->where('contributor_id', $muse->id)
        ->where('slug', 'alternative rock')
        ->value('weight');

    expect($weight)->toBe(100)
        ->and(DB::table('contributor_tags')->count())->toBe($count)
        ->and($count)->toBeGreaterThan(0);
});

it('projects Last.fm artist tags, similar artists and popularity', function (): void {
    $contributor = Contributor::factory()->create(['name' => 'Lord of the Lost']);
    Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, metadataFixture('lastfm-artist-info'));
    Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'top_tags', EnrichmentStatus::Done, metadataFixture('lastfm-artist-top-tags'));
    Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'similar', EnrichmentStatus::Done, metadataFixture('lastfm-artist-similar'));

    resolve(ProjectContributorMetadata::class)->handle($contributor);
    resolve(ProjectContributorMetadata::class)->handle($contributor);

    expect(DB::table('contributor_tags')->where('contributor_id', $contributor->id)->where('source', 'lastfm')->count())->toBe(6)
        ->and(SimilarContributor::query()->where('contributor_id', $contributor->id)->count())->toBe(5)
        ->and($contributor->fresh()?->lastfm_listeners)->toBe(171227)
        ->and(PopularitySample::query()->where('subject_type', Enrichment::CONTRIBUTOR)->count())->toBe(1);
});
