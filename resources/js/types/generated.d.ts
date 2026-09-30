declare namespace App {
namespace Data {
export type AccountData = {
name: string,
channelId: string | null,
thumbnailUrl: string | null,
isPremium: boolean,
};
export type ArtistTallyData = {
name: string,
trackCount: number,
};
export type LibraryData = {
accountName: string,
playlists: App.Data.LibraryPlaylistData[],
lastCheckedAt: string | null,
activeSync: App.Data.YouTubeMusicSyncData | null,
};
export type LibraryPlaylistData = {
id: string,
title: string,
thumbnailUrl: string | null,
trackCount: number | null,
lastChangedAt: string | null,
isRemoved: boolean,
};
export type LibraryStatsData = {
playlistCount: number,
trackCount: number,
artistCount: number,
totalHours: number,
topArtists: App.Data.ArtistTallyData[],
};
export type PlaylistData = {
id: string,
title: string,
description: string | null,
trackCount: number | null,
duration: string | null,
thumbnailUrl: string | null,
author: string | null,
tracks: App.Data.TrackData[],
};
export type PlaylistSummaryData = {
id: string,
title: string,
description: string | null,
trackCount: number | null,
thumbnailUrl: string | null,
author: string | null,
};
export type PlaylistSyncStateData = {
lastCheckedAt: string,
lastChangedAt: string | null,
removedAt: string | null,
};
export type SampledTrackData = {
track: App.Data.TrackData,
playlistId: string,
playlistTitle: string,
};
export type TrackData = {
videoId: string | null,
title: string,
artists: string,
album: string | null,
duration: string | null,
durationSeconds: number | null,
thumbnailUrl: string | null,
isExplicit: boolean,
isAvailable: boolean,
};
export type YouTubeMusicSyncData = {
id: string,
status: App.Enums.YouTubeMusicSyncStatus,
totalPlaylists: number | null,
syncedPlaylists: number,
currentPlaylistTitle: string | null,
errorMessage: string | null,
};
}
namespace Enums {
export type ListenEndReason = 'ended' | 'skipped' | 'previous' | 'jumped' | 'replaced' | 'picked' | 'error' | 'abandoned';
export type ListenOrigin = 'playlist' | 'search' | 'suggestion' | 'queue' | 'autoplay';
export type Locale = 'fr_BE' | 'en_US';
export type YouTubeMusicSyncStatus = 'pending' | 'syncing' | 'completed' | 'failed';
}
}
