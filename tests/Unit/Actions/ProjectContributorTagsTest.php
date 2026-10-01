<?php

declare(strict_types=1);

use App\Actions\ProjectContributorTags;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use Illuminate\Support\Facades\DB;

it('projects the artist tags and rebuilds them on refresh', function (): void {
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    Enrichment::store(Enrichment::CONTRIBUTOR, $muse->id, MetadataSource::MusicBrainz, 'artist', EnrichmentStatus::Done, metadataFixture('musicbrainz-artist'));

    resolve(ProjectContributorTags::class)->handle($muse);
    $count = DB::table('contributor_tags')->count();
    resolve(ProjectContributorTags::class)->handle($muse);

    $weight = DB::table('contributor_tags')
        ->join('tags', 'tags.id', '=', 'contributor_tags.tag_id')
        ->where('contributor_id', $muse->id)
        ->where('slug', 'alternative rock')
        ->value('weight');

    expect($weight)->toBe(100)
        ->and(DB::table('contributor_tags')->count())->toBe($count)
        ->and($count)->toBeGreaterThan(0);
});
