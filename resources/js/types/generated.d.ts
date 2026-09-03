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
}
}
