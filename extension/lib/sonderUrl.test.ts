import { describe, expect, it } from 'vitest';
import { matchPattern, sonderUrlFor } from './sonderUrl';

describe('sonderUrlFor', () => {
    it('points development builds at the local server', () => {
        expect(sonderUrlFor('development')).toBe('http://localhost:8003');
    });

    it('points production builds at Sonder', () => {
        expect(sonderUrlFor('production')).toBe('https://sonder.mishaa.dev');
    });

    it('lets WXT_SONDER_URL win', () => {
        expect(sonderUrlFor('development', 'https://sonder.test/')).toBe(
            'https://sonder.test',
        );
    });
});

describe('matchPattern', () => {
    it('drops the port, which match patterns do not accept', () => {
        expect(matchPattern('http://localhost:8003')).toBe(
            'http://localhost/*',
        );
        expect(matchPattern('https://sonder.mishaa.dev')).toBe(
            'https://sonder.mishaa.dev/*',
        );
    });
});
