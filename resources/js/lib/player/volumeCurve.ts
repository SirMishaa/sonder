/**
 * The volume slider follows a square curve, like hearing does: its lower
 * half covers 1 to 25 %, where fine steps matter most. Rounding up gives
 * every whole percent its own stretch of the slider, so the quietest volume
 * only takes its first tenth. YouTube's player takes whole percents only,
 * so 1 % stays the quietest sound it can make.
 */
export function positionToVolume(position: number): number {
    if (position <= 0) {
        return 0;
    }

    return Math.min(100, Math.ceil(100 * (Math.min(position, 100) / 100) ** 2));
}

/** Where a volume sits on the slider: the middle of its stretch. */
export function volumeToPosition(volume: number): number {
    const clamped = Math.max(0, Math.min(volume, 100));

    return clamped === 0 ? 0 : 100 * Math.sqrt((clamped - 0.5) / 100);
}
