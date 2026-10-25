/** Something on YouTube the owner can send to Sonder. */
export type Target =
    | { kind: 'video'; videoId: string; title: string; artists: string }
    | {
          kind: 'mix';
          listId: string;
          seedVideoId: string | null;
          title: string;
      };

const VIDEO_ID = /^[\w-]{11}$/;

function text(element: Element | null | undefined): string {
    return element?.textContent?.trim() ?? '';
}

function link(card: Element): URL | null {
    const href = card
        .querySelector('a[href^="/watch"], a[href^="/playlist"]')
        ?.getAttribute('href');

    return href ? new URL(href, 'https://www.youtube.com') : null;
}

/** Lockups (home, sidebar) carry what they show in a `content-id-…` class. */
function fromLockup(card: Element): Target | null {
    const host = card.querySelector('[class*="content-id-"]') ?? card;
    const contentId = /content-id-([\w-]+)/.exec(host.className)?.[1];
    const url = link(card);
    const title =
        card.querySelector('h3')?.getAttribute('title') ??
        text(card.querySelector('h3'));

    if (!contentId || !url) {
        return null;
    }

    if (VIDEO_ID.test(contentId)) {
        return {
            kind: 'video',
            videoId: contentId,
            title,
            artists: text(card.querySelector('[class*="MetadataText"]')),
        };
    }

    return {
        kind: 'mix',
        listId: contentId,
        seedVideoId: url.searchParams.get('v'),
        title,
    };
}

/** Classic renderers (search results, older layouts). */
function fromRenderer(card: Element): Target | null {
    const url = link(card);
    const videoId = url?.searchParams.get('v');

    if (!videoId || !VIDEO_ID.test(videoId)) {
        return null;
    }

    return {
        kind: 'video',
        videoId,
        title: text(card.querySelector('#video-title')),
        artists: text(card.querySelector('ytd-channel-name a')),
    };
}

/**
 * Reads what a YouTube card shows. A video card's link often starts a radio
 * (`list=RD…`), so the card type, not the link, says whether it is a mix.
 * Unknown cards (ads, shorts shelves) give null.
 */
export function targetFromCard(card: Element): Target | null {
    switch (card.tagName.toLowerCase()) {
        case 'yt-lockup-view-model':
            return fromLockup(card);
        case 'ytd-video-renderer':
        case 'ytd-compact-video-renderer':
        case 'ytd-rich-grid-media':
            return fromRenderer(card);
        case 'ytd-rich-item-renderer': {
            const inner = card.querySelector(
                'yt-lockup-view-model, ytd-rich-grid-media',
            );

            return inner ? targetFromCard(inner) : null;
        }
        default:
            return null;
    }
}

/** The video being watched, or null away from a watch page. */
export function targetFromWatchPage(doc: Document, url: URL): Target | null {
    const videoId =
        url.pathname === '/watch' ? url.searchParams.get('v') : null;

    if (!videoId || !VIDEO_ID.test(videoId)) {
        return null;
    }

    return {
        kind: 'video',
        videoId,
        title: text(doc.querySelector('ytd-watch-metadata h1')),
        artists: text(
            doc.querySelector('ytd-watch-metadata ytd-channel-name a'),
        ),
    };
}

/** The YouTube cards whose ⋮ menu gets the Sonder group. */
export const CARD_SELECTOR =
    'yt-lockup-view-model, ytd-video-renderer, ytd-compact-video-renderer, ytd-rich-item-renderer, ytd-rich-grid-media';
