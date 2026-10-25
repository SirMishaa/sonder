/** Sonder's origin, set at build time with WXT_SONDER_URL. */
export const SONDER_URL: string =
    import.meta.env.WXT_SONDER_URL ?? 'https://sonder.mishaa.dev';

export const CONNECT_URL = `${SONDER_URL}/settings/extension`;
