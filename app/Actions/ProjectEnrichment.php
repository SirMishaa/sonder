<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditType;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\Tag;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\Credit;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use App\Support\MusicText;
use Illuminate\Support\Facades\DB;

final readonly class ProjectEnrichment
{
    /**
     * Rebuilds the recording's credits and tags from every stored payload,
     * and fills identity fields it lacks. Safe to run again at any time.
     *
     * @return list<Contributor> main artists with an MBID MusicBrainz has not described yet
     */
    public function handle(Recording $recording): array
    {
        $creditsFm = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc');
        $musicBrainz = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording');

        $credits = [];

        foreach ($creditsFm === null ? [] : CreditsFmMapper::credits($creditsFm) as $credit) {
            $credits[] = ['credit' => $credit, 'source' => MetadataSource::CreditsFm];
        }

        $registry = $musicBrainz === null ? null : MusicBrainzMapper::recording($musicBrainz);

        foreach ($registry === null ? [] : $registry->artists as $artist) {
            $credits[] = ['credit' => new Credit($artist['name'], CreditType::Artist, '', $artist['mbid'], null), 'source' => MetadataSource::MusicBrainz];
        }

        $tags = $musicBrainz === null ? [] : MusicBrainzMapper::tags($musicBrainz);
        $detail = $creditsFm === null ? null : CreditsFmMapper::detail($creditsFm);

        return DB::transaction(function () use ($recording, $credits, $tags, $detail, $registry): array {
            $recording->update([...array_filter([
                'iswc' => $recording->iswc ?? $detail?->iswc,
                'release_date' => $recording->release_date ?? $detail->releaseDate ?? $registry?->firstReleaseDate,
            ], fn (mixed $value): bool => $value !== null), 'projected_at' => now()]);

            RecordingContributor::query()->where('recording_id', $recording->id)->delete();
            DB::table('recording_tags')->where('recording_id', $recording->id)->delete();

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

                if ($credit->type === CreditType::Artist && $contributor->mbid !== null) {
                    $mainArtists[$contributor->id] = $contributor;
                }
            }

            foreach ($tags as $tag) {
                DB::table('recording_tags')->insertOrIgnore([
                    'recording_id' => $recording->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => MetadataSource::MusicBrainz->value,
                    'weight' => $tag->weight,
                ]);
            }

            return array_values(array_filter(
                $mainArtists,
                fn (Contributor $contributor): bool => Enrichment::query()
                    ->where('subject_type', Enrichment::CONTRIBUTOR)
                    ->where('subject_key', $contributor->id)
                    ->where('source', MetadataSource::MusicBrainz)
                    ->doesntExist(),
            ));
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
