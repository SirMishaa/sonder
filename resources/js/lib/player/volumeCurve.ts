/**
 * The volume slider follows a square curve, like hearing does: its lower
 * half covers 0 to 25 %, where fine steps matter most. Volumes are
 * continuous; YouTube rounds them up to whole percents and the Sonder
 * extension, when present, makes up the rest (see splitVolume).
 */
export function positionToVolume(position: number): number {
    const clamped = Math.max(0, Math.min(position, 100));

    return 100 * (clamped / 100) ** 2;
}

/** Where a volume sits on the slider, from 0 to 100. */
export function volumeToPosition(volume: number): number {
    return 100 * Math.sqrt(Math.max(0, Math.min(volume, 100)) / 100);
}
