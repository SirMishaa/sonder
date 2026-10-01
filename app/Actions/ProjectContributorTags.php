<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Tag;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use Illuminate\Support\Facades\DB;

final readonly class ProjectContributorTags
{
    /**
     * Rebuilds the contributor's tags from its stored MusicBrainz artist.
     */
    public function handle(Contributor $contributor): void
    {
        $payload = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::MusicBrainz, 'artist');

        DB::transaction(function () use ($contributor, $payload): void {
            DB::table('contributor_tags')
                ->where('contributor_id', $contributor->id)
                ->where('source', MetadataSource::MusicBrainz->value)
                ->delete();

            foreach ($payload === null ? [] : MusicBrainzMapper::tags($payload) as $tag) {
                DB::table('contributor_tags')->insertOrIgnore([
                    'contributor_id' => $contributor->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => MetadataSource::MusicBrainz->value,
                    'weight' => $tag->weight,
                ]);
            }
        });
    }
}
