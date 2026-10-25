/**
 * The volume slider follows a cubic curve, like hearing does: its lower half
 * covers 1 to 13 %, where fine steps matter most. YouTube's player takes
 * whole percents only, so 1 % stays the quietest sound it can make.
 */
export function positionToVolume(position: number): number {
    if (position <= 0) {
        return 0;
    }

    return Math.max(1, Math.round(100 * (Math.min(position, 100) / 100) ** 3));
}

/** Where a volume sits on the slider, from 0 to 100. */
export function volumeToPosition(volume: number): number {
    return 100 * Math.cbrt(Math.max(0, Math.min(volume, 100)) / 100);
}
