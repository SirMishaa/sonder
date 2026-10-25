import { afterEach, describe, expect, it, vi } from 'vitest';
import { initFaro, reportIssue, stopFaro, trackAction } from './faro';

function fakeFaro() {
    return { api: { pushEvent: vi.fn() } };
}

describe('faro', () => {
    afterEach(() => stopFaro());

    it('stays off without a collector URL', () => {
        const create = vi.fn(fakeFaro);

        expect(
            initFaro(
                { url: '', app: 'sonder-extension', version: '1.0.0' },
                create,
            ),
        ).toBeNull();
        expect(create).not.toHaveBeenCalled();
        expect(() => reportIssue('menu.unknown-card')).not.toThrow();
    });

    it('reports an issue when on', () => {
        const faro = fakeFaro();
        initFaro(
            {
                url: 'https://faro.example/collect',
                app: 'sonder-extension',
                version: '1.0.0',
            },
            () => faro,
        );

        reportIssue('menu.unknown-card', { tag: 'yt-lockup-view-model' });

        expect(faro.api.pushEvent).toHaveBeenCalledWith('sonder.issue', {
            name: 'menu.unknown-card',
            tag: 'yt-lockup-view-model',
        });
    });

    it('records each action with the trace it started', () => {
        const faro = fakeFaro();
        initFaro(
            {
                url: 'https://faro.example/collect',
                app: 'sonder-extension',
                version: '1.0.0',
            },
            () => faro,
        );

        trackAction({
            action: 'enqueue',
            kind: 'mix',
            items: 25,
            traceparent: '00-abc-def-01',
        });

        expect(faro.api.pushEvent).toHaveBeenCalledWith('sonder.action', {
            action: 'enqueue',
            kind: 'mix',
            items: '25',
            traceparent: '00-abc-def-01',
        });
    });
});
