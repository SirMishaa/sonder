import { matchPattern, sonderUrlFor } from './sonderUrl';

/** Sonder's origin for this build (see sonderUrlFor). */
export const SONDER_URL: string = sonderUrlFor(
    import.meta.env.MODE,
    import.meta.env.WXT_SONDER_URL,
);

/** Match pattern for Sonder's pages, for content scripts and tab queries. */
export const SONDER_PAGES: string = matchPattern(SONDER_URL);

export const CONNECT_URL = `${SONDER_URL}/settings/extension`;
