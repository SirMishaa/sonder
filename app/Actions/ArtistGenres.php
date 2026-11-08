<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;

final readonly class ArtistGenres
{
    /**
     * The strongest genres of the artist with this name, weights summed
     * across sources.
     *
     * @return list<string>
     */
    public function handle(string $artistName, int $limit = 4): array
    {
        $names = [];

        DB::table('contributors')
            ->join('contributor_tags', 'contributor_tags.contributor_id', '=', 'contributors.id')
            ->join('tags', 'tags.id', '=', 'contributor_tags.tag_id')
            ->whereRaw('lower(contributors.name) = ?', [mb_strtolower(mb_trim($artistName))])
            ->where('tags.is_genre', true)
            ->selectRaw('tags.name, sum(contributor_tags.weight) as weight')
            ->groupBy('tags.name')
            ->orderByDesc('weight')
            ->orderBy('tags.name')
            ->limit($limit)
            ->get()
            ->each(function (object $row) use (&$names): void {
                $name = $row->name ?? null;

                if (is_string($name)) {
                    $names[] = $name;
                }
            });

        return $names;
    }
}
