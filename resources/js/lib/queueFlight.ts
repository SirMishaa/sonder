/**
 * Motion for "queued for later": the tapped artwork lifts, flies in an arc to
 * the up-next control and lands with a small bump, so a click that does not
 * interrupt playback still visibly did something.
 */

const EASE_OUT = 'cubic-bezier(0.22, 1, 0.36, 1)';
const EASE_IN = 'cubic-bezier(0.55, 0, 0.8, 0.3)';
const FLIGHT_MS = 520;
const AMBER_WASH = 'oklch(0.8 0.15 68 / 0.18)';

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** The open queue panel's heading when visible, else the player bar button. */
function landingTarget(): HTMLElement | null {
    return (
        document.querySelector<HTMLElement>('[data-queue-target="panel"]') ??
        document.querySelector<HTMLElement>('[data-queue-target]')
    );
}

/** Washes the tapped row (or its cells when it is `display: contents`) in amber. */
function wash(row: HTMLElement): void {
    const surfaces =
        getComputedStyle(row).display === 'contents'
            ? [...row.children]
            : [row];

    for (const surface of surfaces) {
        surface.animate(
            {
                backgroundColor: [
                    AMBER_WASH,
                    getComputedStyle(surface).backgroundColor,
                ],
            },
            { duration: 900, easing: EASE_OUT },
        );
    }
}

function bump(target: HTMLElement): void {
    target.animate(
        [
            { transform: 'scale(1)' },
            {
                transform: 'scale(1.1)',
                color: 'oklch(0.8 0.15 68)',
                offset: 0.3,
            },
            { transform: 'scale(1)' },
        ],
        { duration: 420, easing: EASE_OUT },
    );
}

export type Flight = { cancel: () => void };

/**
 * Plays the queued feedback from `row`, flying a copy of `artwork` to the
 * up-next control. Under reduced motion only the row wash remains.
 */
export function flyToQueue(
    row: HTMLElement,
    artwork: HTMLElement | null,
): Flight {
    wash(row);

    const target = landingTarget();

    if (!artwork || !target || prefersReducedMotion()) {
        if (target) {
            bump(target);
        }

        return { cancel: () => undefined };
    }

    artwork.animate([{ transform: 'scale(0.9)' }, { transform: 'scale(1)' }], {
        duration: 320,
        easing: EASE_OUT,
    });

    const from = artwork.getBoundingClientRect();
    const to = target.getBoundingClientRect();
    const size = Math.max(from.width, 28);
    const dx = to.left + to.width / 2 - (from.left + from.width / 2);
    const dy = to.top + to.height / 2 - (from.top + from.height / 2);

    // X and Y run on different curves so the path bends into an arc.
    const carrier = document.createElement('div');
    const ghost = artwork.cloneNode(true) as HTMLElement;
    carrier.setAttribute('aria-hidden', 'true');
    Object.assign(carrier.style, {
        position: 'fixed',
        left: `${from.left + from.width / 2 - size / 2}px`,
        top: `${from.top + from.height / 2 - size / 2}px`,
        width: `${size}px`,
        height: `${size}px`,
        zIndex: '100',
        pointerEvents: 'none',
    });
    Object.assign(ghost.style, {
        width: '100%',
        height: '100%',
        borderRadius: '6px',
        overflow: 'hidden',
        boxShadow: '0 12px 28px -10px oklch(0 0 0 / 0.8)',
    });
    carrier.append(ghost);
    document.body.append(carrier);

    const across = carrier.animate(
        { transform: ['translateX(0)', `translateX(${dx}px)`] },
        { duration: FLIGHT_MS, easing: EASE_OUT },
    );
    const down = ghost.animate(
        [
            { transform: 'translateY(0) scale(1)', opacity: 1 },
            {
                transform: 'translateY(-14px) scale(1.15)',
                opacity: 1,
                offset: 0.18,
            },
            {
                transform: `translateY(${dy}px) scale(0.45)`,
                opacity: 0.2,
            },
        ],
        { duration: FLIGHT_MS, easing: EASE_IN },
    );

    let cancelled = false;
    down.finished
        .then(() => {
            if (!cancelled) {
                bump(target);
            }
        })
        .catch(() => undefined)
        .finally(() => carrier.remove());

    return {
        cancel: () => {
            cancelled = true;
            across.cancel();
            down.cancel();
        },
    };
}
