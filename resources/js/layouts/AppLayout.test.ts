import { expect, it } from 'vite-plus/test';

const pages = import.meta.glob<string>('../pages/**/*.vue', {
    query: '?raw',
    import: 'default',
    eager: true,
});

it('is only ever a persistent layout, so the player survives navigation', () => {
    const wrappingPages = Object.entries(pages)
        .filter(([, source]) => source.includes('<AppLayout'))
        .map(([path]) => path);

    expect(wrappingPages).toEqual([]);
});
