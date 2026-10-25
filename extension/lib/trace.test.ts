import { describe, expect, it } from 'vitest';
import { newTraceparent } from './trace';

describe('newTraceparent', () => {
    it('formats a W3C traceparent', () => {
        const bytes = (length: number) =>
            Uint8Array.from({ length }, (_, index) => index + 1);

        expect(newTraceparent(bytes)).toBe(
            '00-0102030405060708090a0b0c0d0e0f10-0102030405060708-01',
        );
    });

    it('is random by default', () => {
        expect(newTraceparent()).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
        expect(newTraceparent()).not.toBe(newTraceparent());
    });
});
