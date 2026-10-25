/** Where `php artisan dev` serves Sonder (APP_URL in .env). */
const LOCAL_URL = 'http://localhost:8003';

const PRODUCTION_URL = 'https://sonder.mishaa.dev';

/**
 * Sonder's origin for a build: the local server in development (`wxt`,
 * `bun run dev`), production otherwise. WXT_SONDER_URL overrides both.
 */
export function sonderUrlFor(mode: string, override?: string): string {
    const url =
        override || (mode === 'development' ? LOCAL_URL : PRODUCTION_URL);

    return url.replace(/\/+$/, '');
}

/**
 * The match pattern covering a Sonder origin. Match patterns take no port,
 * so `http://localhost:8003` becomes `http://localhost/*`.
 */
export function matchPattern(url: string): string {
    const { protocol, hostname } = new URL(url);

    return `${protocol}//${hostname}/*`;
}
