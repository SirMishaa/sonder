declare namespace App {
namespace Data {
export type ArtistTallyData = {
name: string,
trackCount: number,
};
export type ExtensionTokenData = {
id: string,
createdAt: string,
lastUsedAt: string | null,
};
export type LibraryData = {
accountName: string,
playlists: App.Data.LibraryPlaylistData[],
lastCheckedAt: string | null,
activeSync: App.Data.YouTubeMusicSyncData | null,
};
export type LibraryEnrichmentData = {
state: App.Enums.LibraryEnrichmentState,
total: number,
resolved: number,
notFound: number,
failed: number,
pending: number,
recordings: number,
described: number,
withGenre: number,
withTags: number,
updatedAt: string,
recent: App.Data.RecentEnrichmentData[],
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
export type PlayerCommandData = {
action: App.Enums.PlayerCommandAction,
kind: App.Enums.PlayerCommandKind,
listId: string | null,
title: string,
items: App.Data.PlayerCommandItemData[],
};
export type PlayerCommandItemData = {
videoId: string,
title: string,
artists: string,
durationSeconds: number,
thumbnailUrl: string | null,
};
export type PlayerQueueData = {
tracks: App.Data.QueueTrackData[],
index: number,
source: App.Data.QueueSourceData | null,
origin: App.Enums.ListenOrigin,
version: number,
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
export type QueueSourceData = {
playlistId: string | null,
title: string,
};
export type QueueTrackData = {
key: string,
videoId: string | null,
title: string,
artists: string,
album: string | null,
duration: string | null,
durationSeconds: number,
thumbnailUrl: string | null,
playlistId: string | null,
queued: boolean,
genres: string[],
};
export type RecentEnrichmentData = {
videoId: string,
title: string,
artists: string,
thumbnailUrl: string | null,
genres: string[],
tags: string[],
listeners: number | null,
creditCount: number,
year: number | null,
enrichedAt: string,
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
genres: string[],
};
export type VideoKnowledgeData = {
inLibrary: boolean,
playlists: string[],
listenCount: number,
lastListenedAt: string | null,
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
export type ArtistRole = 'main' | 'featured';
export type CreditType = 'artist' | 'songwriter' | 'publisher' | 'producer' | 'performer';
export type EnrichmentStatus = 'done' | 'not_found' | 'failed';
export type LibraryEnrichmentState = 'idle' | 'running' | 'paused' | 'done' | 'attention';
export type ListenEndReason = 'ended' | 'skipped' | 'previous' | 'jumped' | 'replaced' | 'picked' | 'error' | 'abandoned';
export type ListenOrigin = 'playlist' | 'search' | 'suggestion' | 'queue' | 'autoplay';
export type Locale = 'fr_BE' | 'en_US';
export type LookupOutcome = 'found' | 'not_found' | 'failed';
export type MetadataSource = 'credits_fm' | 'musicbrainz' | 'lastfm' | 'youtube_music';
export type PlayerCommandAction = 'play_now' | 'play_next' | 'enqueue';
export type PlayerCommandKind = 'video' | 'mix';
export type Provider = 'youtube_music';
export type ProviderErrorCode = 'credentials_rejected' | 'unavailable' | 'rate_limited';
export type ResolutionMethod = 'credits_fm' | 'musicbrainz_search' | 'lastfm';
export type ResolutionStatus = 'pending' | 'resolved' | 'not_found' | 'failed';
export type SourceKind = 'audio' | 'video';
export type YouTubeMusicSyncStatus = 'pending' | 'syncing' | 'completed' | 'failed';
}
}
