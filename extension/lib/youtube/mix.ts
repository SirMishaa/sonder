/** One playable track of a mix, as Sonder's command expects it. */
export type MixItem = App.Data.PlayerCommandItemData;

type Runs = { simpleText?: string; runs?: { text?: string }[] };

type PanelVideo = {
    videoId?: string;
    title?: Runs;
    shortBylineText?: Runs;
    lengthText?: Runs;
    thumbnail?: { thumbnails?: { url?: string }[] };
};

const VIDEO_ID = /^[\w-]{11}$/;

function read(text: Runs | undefined): string {
    return (
        text?.simpleText ??
        text?.runs?.map((run) => run.text ?? '').join('') ??
        ''
    ).trim();
}

function seconds(length: string): number {
    return length
        .split(':')
        .map(Number)
        .reduce(
            (total, part) => total * 60 + (Number.isFinite(part) ? part : 0),
            0,
        );
}

function thumbnail(video: PanelVideo): string | null {
    const url = video.thumbnail?.thumbnails?.at(-1)?.url;

    return url ? url.split('?')[0]! : null;
}

/**
 * The playable tracks of a mix in a `/youtubei/v1/next` response. Entries
 * without a video (private, deleted, the "mix continues" preview) are skipped.
 */
export function parseNextResponse(json: unknown): MixItem[] {
    const contents = (
        json as {
            contents?: {
                twoColumnWatchNextResults?: {
                    playlist?: {
                        playlist?: { contents?: Record<string, PanelVideo>[] };
                    };
                };
            };
        } | null
    )?.contents?.twoColumnWatchNextResults?.playlist?.playlist?.contents;

    return (contents ?? [])
        .map((entry) => entry.playlistPanelVideoRenderer)
        .filter((video): video is PanelVideo & { videoId: string } =>
            VIDEO_ID.test(video?.videoId ?? ''),
        )
        .map((video) => ({
            videoId: video.videoId,
            title: read(video.title),
            artists: read(video.shortBylineText),
            durationSeconds: seconds(read(video.lengthText)),
            thumbnailUrl: thumbnail(video),
        }));
}

/**
 * Asks YouTube, as the page itself would and with the owner's session, for
 * the first page of a mix or playlist.
 */
export async function fetchMix(
    listId: string,
    seedVideoId: string | null,
    doc: Document,
    send: typeof fetch = (...args) => fetch(...args),
): Promise<MixItem[]> {
    const clientVersion =
        /"INNERTUBE_CLIENT_VERSION":"([^"]+)"/.exec(
            doc.documentElement.innerHTML,
        )?.[1] ?? '2.20261023.01.00';

    const response = await send('/youtubei/v1/next?prettyPrint=false', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            context: { client: { clientName: 'WEB', clientVersion } },
            playlistId: listId,
            ...(seedVideoId ? { videoId: seedVideoId } : {}),
        }),
    });

    if (!response.ok) {
        throw new Error(`YouTube answered ${response.status}`);
    }

    return parseNextResponse(await response.json());
}
