<?php

declare(strict_types=1);

use App\Services\Thumbnails\ThumbnailProxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Uri\WhatWg\Url;

/**
 * Rewrites the stored thumbnail URLs of tracks and playlists so they end
 * like image files, which the edge network caches. Queues saved with the
 * older URLs keep working, since the route still accepts a bare hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tracks', 'playlists'] as $table) {
            DB::table($table)
                ->whereNotNull('thumbnail_url')
                ->lazyById()
                ->each(function (object $row) use ($table): void {
                    /** @var object{id: int|string, thumbnail_url: string} $row */
                    $renamed = $this->renamed($row->thumbnail_url);

                    if ($renamed !== null) {
                        DB::table($table)->where('id', $row->id)->update(['thumbnail_url' => $renamed]);
                    }
                });
        }
    }

    /**
     * The older URLs resolve as well, so there is nothing to undo.
     */
    public function down(): void
    {
        //
    }

    /**
     * The URL of the same image named like a file, or null when it already
     * is one or does not point at the proxy.
     */
    private function renamed(string $thumbnailUrl): ?string
    {
        $proxied = Url::parse($thumbnailUrl);

        if ($proxied === null || str_contains(basename($proxied->getPath()), '.')) {
            return null;
        }

        parse_str($proxied->getQuery() ?? '', $query);
        $imageUrl = $query['url'] ?? null;

        return is_string($imageUrl) && $imageUrl !== '' ? ThumbnailProxy::url($imageUrl) : null;
    }
};
