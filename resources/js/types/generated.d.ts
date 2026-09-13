declare namespace App {
namespace Data {
export type AccountData = {
name: string,
channelId: string | null,
thumbnailUrl: string | null,
isPremium: boolean,
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
export type YouTubeMusicSyncStatus = 'pending' | 'syncing' | 'completed' | 'failed';
}
}
