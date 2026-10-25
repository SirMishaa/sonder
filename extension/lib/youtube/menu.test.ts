import { beforeEach, describe, expect, it, vi } from 'vitest';
import cards from './fixtures/cards.html?raw';
import { createMenuWatcher } from './menu';

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
});
