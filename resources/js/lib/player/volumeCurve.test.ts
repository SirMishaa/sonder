import { describe, expect, it } from 'vite-plus/test';
import { positionToVolume, volumeToPosition } from '@/lib/player/volumeCurve';

describe('volume curve', () => {
    it('keeps both ends', () => {
        expect(positionToVolume(0)).toBe(0);
        expect(positionToVolume(100)).toBe(100);
    });

    it('spends more of the slider on quiet volumes', () => {
        expect(positionToVolume(50)).toBe(25);
        expect(positionToVolume(25)).toBe(6.25);
    });

    it('goes below one percent for the extension to make it audible', () => {
        expect(positionToVolume(5)).toBeCloseTo(0.25);
        expect(positionToVolume(1)).toBeCloseTo(0.01);
    });

    it('places a stored volume back on the slider', () => {
        for (const volume of [0, 0.25, 1, 7, 25, 64, 100]) {
            expect(positionToVolume(volumeToPosition(volume))).toBeCloseTo(
                volume,
            );
        }
    });
});
