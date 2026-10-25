import { beforeEach, describe, expect, it, vi } from 'vitest';
import cards from './fixtures/cards.html?raw';
import { closeMenu, createMenuWatcher, fitMenu } from './menu';

const POPUP = `
    <ytd-popup-container>
        <tp-yt-iron-dropdown>
            <yt-sheet-view-model><div><yt-list-view-model id="list">
                <yt-list-item-view-model>Add to queue</yt-list-item-view-model>
            </yt-list-view-model></div></yt-sheet-view-model>
        </tp-yt-iron-dropdown>
    </ytd-popup-container>`;

function setup() {
    document.body.innerHTML = cards + POPUP;
    const mount = vi.fn();
    const report = vi.fn();
    const watcher = createMenuWatcher({ mount, report });
    const list = document.querySelector('#list')!;

    function openMenuOf(cardId: string) {
        const button = document.createElement('button');
        button.setAttribute('aria-label', 'More actions');
        document.querySelector(`#${cardId}`)!.firstElementChild!.append(button);
        button.addEventListener('click', watcher.onClick);
        button.click();
        watcher.onPopup(list);
    }

    return { mount, report, list, openMenuOf, watcher };
}

describe('menu watcher', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('adds the group to the menu of a video card', () => {
        const { mount, list, openMenuOf } = setup();

        openMenuOf('video-lockup');

        expect(mount).toHaveBeenCalledWith(list, {
            kind: 'video',
            videoId: 'dbB-mICjkQM',
            title: 'Muse - Plug In Baby (Official Video)',
            artists: 'Muse',
        });
    });

    it('adds the group once', () => {
        const { mount, list, openMenuOf, watcher } = setup();

        openMenuOf('mix-lockup');
        watcher.onPopup(list);

        expect(mount).toHaveBeenCalledOnce();
    });

    it('does nothing for an unknown card', () => {
        const { mount, report, openMenuOf } = setup();
        document
            .querySelector('#video-lockup [class*="content-id-"]')!
            .setAttribute('class', 'ytLockupViewModelHost');

        expect(() => openMenuOf('video-lockup')).not.toThrow();
        expect(mount).not.toHaveBeenCalled();
        expect(report).toHaveBeenCalledOnce();
    });

    it('adds the group to the menu of the mix being played', () => {
        const mount = vi.fn();
        const mix = {
            kind: 'mix' as const,
            listId: 'RDx',
            seedVideoId: null,
            title: 'Mix',
        };
        const watcher = createMenuWatcher({
            mount,
            report: vi.fn(),
            playlistPanel: () => mix,
        });
        document.body.innerHTML = `
            <ytd-playlist-panel-renderer><div id="header-contents">
                <button aria-label="Action menu"></button>
            </div></ytd-playlist-panel-renderer>
            <yt-list-view-model id="list"></yt-list-view-model>`;
        const button = document.querySelector('button')!;
        button.addEventListener('click', watcher.onClick);

        button.click();
        watcher.onPopup(document.querySelector('#list')!);

        expect(mount).toHaveBeenCalledWith(
            document.querySelector('#list'),
            mix,
        );
    });

    it('ignores menus that do not belong to a card', () => {
        const { mount, report, list, watcher } = setup();
        const avatar = document.createElement('button');
        document.body.append(avatar);
        avatar.addEventListener('click', watcher.onClick);

        avatar.click();
        watcher.onPopup(list);

        expect(mount).not.toHaveBeenCalled();
        expect(report).not.toHaveBeenCalled();
    });

    it('frees the menu to fit the Sonder group, inside the window', () => {
        document.body.innerHTML = `
            <tp-yt-iron-dropdown style="position: fixed; left: 1884px; top: 1154px">
                <ytd-menu-popup-renderer style="max-width: 129px; max-height: 52px">
                    <tp-yt-paper-listbox id="items"></tp-yt-paper-listbox>
                </ytd-menu-popup-renderer>
            </tp-yt-iron-dropdown>`;
        const dropdown = document.querySelector<HTMLElement>(
            'tp-yt-iron-dropdown',
        )!;
        const sheet = document.querySelector<HTMLElement>(
            'ytd-menu-popup-renderer',
        )!;
        dropdown.getBoundingClientRect = () =>
            ({
                top: 1154,
                left: 1884,
                width: 242,
                height: 220,
                bottom: 1374,
                right: 2126,
            }) as DOMRect;

        fitMenu(document.querySelector('#items')!, {
            width: 2000,
            height: 1216,
        });

        expect(sheet.style.maxWidth).toBe('');
        expect(sheet.style.maxHeight).toBe('');
        expect(dropdown.style.top).toBe('988px');
        expect(dropdown.style.left).toBe('1750px');
    });

    it('closes the menu the way a press on Escape does', () => {
        document.body.innerHTML = `
            <tp-yt-iron-dropdown><tp-yt-paper-listbox id="items"></tp-yt-paper-listbox></tp-yt-iron-dropdown>`;
        const pressed = vi.fn();
        document
            .querySelector('tp-yt-iron-dropdown')!
            .addEventListener('keydown', (event) =>
                pressed((event as KeyboardEvent).key),
            );

        closeMenu(document.querySelector('#items')!);

        expect(pressed).toHaveBeenCalledWith('Escape');
    });
});
