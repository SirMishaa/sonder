import { CARD_SELECTOR, targetFromCard } from './targets';
import type { Target } from './targets';

export type MenuWatcher = {
    /**
     * Click listener (capture phase) that remembers which card's ⋮ was
     * opened. Says whether a Sonder menu is expected.
     */
    onClick: (event: Event) => boolean;
    /** Called with the list of a menu that just opened. */
    onPopup: (list: Element) => void;
};

const MOUNTED = 'sonderGroup';

/**
 * Ties a YouTube menu popup to the card whose ⋮ button opened it, and asks
 * for the Sonder group to be mounted in it once. A card YouTube no longer
 * describes the way Sonder expects is reported, never thrown.
 */
export function createMenuWatcher(deps: {
    mount(list: Element, target: Target): void;
    report(name: string, attributes?: Record<string, string>): void;
    watchPage?: () => Target | null;
}): MenuWatcher {
    let pending: Target | null = null;
    let opened = 0;

    return {
        onClick(event) {
            const button = (event.target as Element | null)?.closest?.(
                'button',
            );
            const card = button?.closest(CARD_SELECTOR);
            pending = null;

            if (!button) {
                return false;
            }

            if (card) {
                pending = targetFromCard(card);
                opened++;

                if (pending === null) {
                    deps.report('menu.unknown-card', {
                        tag: card.tagName.toLowerCase(),
                    });
                }

                return pending !== null;
            }

            if (button.closest('ytd-watch-metadata')) {
                pending = deps.watchPage?.() ?? null;
                opened++;
            }

            return pending !== null;
        },
        onPopup(list) {
            if (pending === null) {
                return;
            }

            const html = list as HTMLElement;

            if (html.dataset[MOUNTED] === String(opened)) {
                return;
            }

            html.dataset[MOUNTED] = String(opened);
            list.querySelector('[data-sonder-group]')?.remove();
            deps.mount(list, pending);
        },
    };
}

const MARGIN = 8;

/**
 * Lets a YouTube menu grow to hold the Sonder group. YouTube caps the menu's
 * size when it opens and never measures it again, which hid the group behind
 * scrollbars; the menu is then moved back inside the window.
 */
export function fitMenu(
    list: Element,
    viewport: { width: number; height: number } = {
        width: window.innerWidth,
        height: window.innerHeight,
    },
): void {
    const sheet = list.closest<HTMLElement>(
        'ytd-menu-popup-renderer, yt-sheet-view-model',
    );
    const dropdown = list.closest<HTMLElement>('tp-yt-iron-dropdown');

    sheet?.style.removeProperty('max-width');
    sheet?.style.removeProperty('max-height');

    if (!dropdown) {
        return;
    }

    const box = dropdown.getBoundingClientRect();

    if (box.bottom > viewport.height - MARGIN) {
        dropdown.style.top = `${Math.max(MARGIN, viewport.height - box.height - MARGIN)}px`;
    }

    if (box.right > viewport.width - MARGIN) {
        dropdown.style.left = `${Math.max(MARGIN, viewport.width - box.width - MARGIN)}px`;
    }
}

/**
 * Closes a YouTube menu. Its Polymer methods live in the page's world, out
 * of a content script's reach, so this presses Escape like the owner would.
 */
export function closeMenu(list: Element): void {
    list.closest('tp-yt-iron-dropdown')?.dispatchEvent(
        new KeyboardEvent('keydown', {
            key: 'Escape',
            code: 'Escape',
            keyCode: 27,
            bubbles: true,
            composed: true,
        }),
    );
}
