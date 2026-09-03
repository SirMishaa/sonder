<?php

declare(strict_types=1);

namespace App\Services\YouTubeMusic;

use App\Data\AccountData;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Exceptions\YouTubeMusicException;

/**
 * A read-only view of a YouTube Music account.
 *
 * Every method takes the raw browser cookie rather than the client holding it,
 * so implementations stay stateless. This is deliberate: the application runs
 * on Octane, where a client carrying per-request credentials in a property
 * would leak them into the next request served by the same worker.
 */
interface Client
{
    /**
     * @throws YouTubeMusicException
     */
    public function account(string $cookie): AccountData;

    /**
     * @return array<int, PlaylistSummaryData>
     *
     * @throws YouTubeMusicException
     */
    public function playlists(string $cookie): array;

    /**
     * @throws YouTubeMusicException
     */
    public function playlist(string $cookie, string $playlistId, ?int $trackCount = null): PlaylistData;
}
