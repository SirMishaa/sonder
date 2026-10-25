import { describe, expect, it } from 'vite-plus/test';
import { positionToVolume, volumeToPosition } from '@/lib/player/volumeCurve';

describe('volume curve', () => {
    it('keeps both ends', () => {
        expect(positionToVolume(0)).toBe(0);
        expect(positionToVolume(100)).toBe(100);
    });

    it('spends more of the slider on quiet volumes', () => {
        expect(positionToVolume(50)).toBe(25);
        expect(positionToVolume(25)).toBe(7);
    });

    it('leaves no long stretch of the slider on the quietest volume', () => {
        expect(positionToVolume(1)).toBe(1);
        expect(positionToVolume(11)).toBe(2);
    });

    it('gives every volume its own stretch of the slider', () => {
        const volumes = new Set(
            Array.from({ length: 1001 }, (_, step) =>
                positionToVolume(step / 10),
            ),
        );

        expect(volumes.size).toBe(101);
    });

    it('places a stored volume back on the slider', () => {
        expect(volumeToPosition(0)).toBe(0);
        expect(positionToVolume(volumeToPosition(100))).toBe(100);

        for (const volume of [1, 2, 7, 13, 25, 64]) {
            expect(positionToVolume(volumeToPosition(volume))).toBe(volume);
        }
    });
});
