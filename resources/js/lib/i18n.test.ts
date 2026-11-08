import { describe, expect, it } from 'vite-plus/test';
import { parseCompactCount } from '@/lib/i18n';

describe('parseCompactCount', () => {
    it('reads the abbreviated counts YouTube Music gives', () => {
        expect(parseCompactCount('43.3M')).toBe(43_300_000);
        expect(parseCompactCount('2.41K')).toBe(2410);
        expect(parseCompactCount('1.2B')).toBe(1_200_000_000);
        expect(parseCompactCount('870')).toBe(870);
    });

    it('reads grouped digits and surrounding spaces', () => {
        expect(parseCompactCount(' 1,234,567 ')).toBe(1_234_567);
        expect(parseCompactCount('3.5 m')).toBe(3_500_000);
    });

    it('gives null for anything it cannot read', () => {
        expect(parseCompactCount('')).toBeNull();
        expect(parseCompactCount('many')).toBeNull();
        expect(parseCompactCount('43,3 M')).toBeNull();
        expect(parseCompactCount('12X')).toBeNull();
    });
});
