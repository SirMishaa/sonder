import { describe, expect, it } from 'vite-plus/test';
import { positionToVolume, volumeToPosition } from '@/lib/player/volumeCurve';

describe('volume curve', () => {
    it('keeps both ends', () => {
        expect(positionToVolume(0)).toBe(0);
        expect(positionToVolume(100)).toBe(100);
    });

    it('spends the lower half of the slider on quiet volumes', () => {
        expect(positionToVolume(50)).toBe(13);
        expect(positionToVolume(25)).toBe(2);
    });

    it('never mutes before the slider reaches zero', () => {
        expect(positionToVolume(1)).toBe(1);
    });

    it('places a stored volume back on the slider', () => {
        expect(volumeToPosition(0)).toBe(0);
        expect(volumeToPosition(100)).toBe(100);
        expect(positionToVolume(volumeToPosition(13))).toBe(13);
    });
});
