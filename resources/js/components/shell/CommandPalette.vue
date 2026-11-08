<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useLocalStorage } from '@vueuse/core';
import { trans, transChoice } from 'laravel-vue-i18n';
import {
    Cable,
    Clock,
    CornerDownLeft,
    ListMusic,
    PanelLeft,
    Pause,
    Play,
    Search,
    Settings,
    Sparkles,
    SquareLibrary,
    X,
} from 'lucide-vue-next';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import type { Component } from 'vue';
import { computed, nextTick, ref, watch } from 'vue';
import AlbumController from '@/actions/App/Http/Controllers/AlbumController';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import BrowsePlaylistController from '@/actions/App/Http/Controllers/BrowsePlaylistController';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import SearchController from '@/actions/App/Http/Controllers/SearchController';
import UserProfileController from '@/actions/App/Http/Controllers/UserProfileController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Artwork from '@/components/music/Artwork.vue';
import PalettePreview from '@/components/shell/PalettePreview.vue';
import { useCatalogSuggestions } from '@/composables/useCatalogSuggestions';
import { useListSelection } from '@/composables/useListSelection';
import { usePalette } from '@/composables/usePalette';
import { usePlayer } from '@/composables/usePlayer';
import { useRecentSearches } from '@/composables/useRecentSearches';
import type { RecentItem } from '@/composables/useRecentSearches';
import { useToast } from '@/composables/useToast';

type Group =
    | 'Recent'
    | 'In your library'
    | 'Up next'
    | 'Top result'
    | 'Tracks'
    | 'Albums'
    | 'Artists'
    | 'Playlists'
    | 'Go to'
    | 'Actions';

type Item = {
    id: string;
    group: Group;
    label: string;
    sub?: string;
    hint?: string;
    artwork?: string | null;
    round?: boolean;
    icon?: Component;
    /** What Enter does, for the footer. */
    action: string;
    /** Enter. */
    run: () => void;
    /** Shift+Enter and the row's play button. */
    play?: () => void;
    /** Keeps the palette open after `run`. */
    keepOpen?: boolean;
    /** Removes the item from the recent searches. */
    remove?: () => void;
    previewTracks?: App.Data.CatalogTrackData[];
    previewHint?: string;
};

type Section =
    | { type: 'group'; group: Group; entries: { item: Item; index: number }[] }
    | { type: 'catalog-status' };

const LIBRARY_GROUPS: Group[] = ['In your library', 'Up next'];
const MAX_PER_GROUP = 6;

const page = usePage();
const palette = usePalette();
const player = usePlayer();
const recent = useRecentSearches();
const { toast } = useToast();
const collapsed = useLocalStorage('sonder.sidebar.collapsed', false);

const query = ref('');
const list = ref<HTMLElement | null>(null);

const suggestions = useCatalogSuggestions(query);

const needle = computed(() => query.value.replace(/\s+/g, ' ').trim());
const isTyping = computed(() => needle.value !== '');
const searchesCatalog = computed(() => needle.value.length >= 2);

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.userAgent);
const modKey = isMac ? '⌘' : 'Ctrl';

const source = computed(() => ({
    playlistId: null,
    title: trans('Search “:query”', { query: needle.value }),
}));

function openArtist(artist: App.Data.CatalogArtistData): void {
    recent.add({
        id: artist.id,
        kind: 'artist',
        label: artist.name,
        artwork: artist.thumbnailUrl,
    });
    router.visit(ArtistController.show(artist.id));
}

function openAlbum(album: App.Data.CatalogAlbumData): void {
    recent.add({
        id: album.id,
        kind: 'album',
        label: album.title,
        sub: [album.type, album.artists].filter(Boolean).join(' · '),
        artwork: album.thumbnailUrl,
    });
    router.visit(AlbumController.show(album.id));
}

function isPlaying(track: App.Data.CatalogTrackData): boolean {
    return (
        track.videoId !== null &&
        player.current.value?.videoId === track.videoId
    );
}

/** Enter on a track: queues it next, like a click while something plays. */
function queueNext(track: App.Data.CatalogTrackData, position: number): void {
    if (isPlaying(track)) {
        player.toggle();

        return;
    }

    const wasPlaying = player.current.value !== null;
    player.queueNext(track, position, source.value);

    if (wasPlaying) {
        toast(trans('Queued :title next'), track.title);
    }
}

/** Shift+Enter on a track: plays it now. */
function playNow(track: App.Data.CatalogTrackData): void {
    if (isPlaying(track)) {
        if (!player.state.playing) {
            player.toggle();
        }

        return;
    }

    player.playNext(track, source.value, 'search');
}

function searchAll(): void {
    if (!needle.value) {
        return;
    }

    recent.add({ id: needle.value, kind: 'query', label: needle.value });
    palette.hide();
    router.visit(SearchController.index({ query: { q: needle.value } }));
}

function recentItem(entry: RecentItem): Item {
    const base = {
        id: `recent-${entry.kind}-${entry.id}`,
        group: 'Recent' as const,
        label: entry.label,
        remove: () => recent.remove(entry.id),
    };

    switch (entry.kind) {
        case 'query':
            return {
                ...base,
                sub: trans('Search'),
                icon: Clock,
                action: trans('Search'),
                keepOpen: true,
                run: () => (query.value = entry.label),
            };
        case 'artist':
            return {
                ...base,
                sub: trans('Artist'),
                artwork: entry.artwork ?? null,
                round: true,
                action: trans('Open'),
                run: () => {
                    recent.add(entry);
                    router.visit(ArtistController.show(entry.id));
                },
            };
        case 'album':
            return {
                ...base,
                sub: entry.sub,
                artwork: entry.artwork ?? null,
                action: trans('Open'),
                run: () => {
                    recent.add(entry);
                    router.visit(AlbumController.show(entry.id));
                },
            };
    }
}

const catalogItems = computed<Item[]>(() => {
    const results = suggestions.results.value;

    if (!searchesCatalog.value || !results) {
        return [];
    }

    const openHint = trans('Enter to open');
    const trackHint = trans('Enter to queue next, Shift+Enter to play now');
    const items: Item[] = [];
    const top = results.topArtist;

    if (top) {
        const own = results.tracks.filter(
            (track) => track.artistId === top.id && track.isAvailable,
        );
        const playable = own.length
            ? own
            : results.tracks.filter((track) => track.isAvailable);

        items.push({
            id: `top-${top.id}`,
            group: 'Top result',
            label: top.name,
            sub: top.subscribers
                ? `${trans('Artist')} · ${trans(':count subscribers', { count: top.subscribers })}`
                : trans('Artist'),
            artwork: top.thumbnailUrl,
            round: true,
            action: trans('Open'),
            run: () => openArtist(top),
            play: playable.length
                ? () => player.playTracks(playable, 0, source.value, 'search')
                : undefined,
            previewTracks: own,
            previewHint: openHint,
        });
    }

    results.tracks.forEach((track, position) => {
        if (!track.isAvailable) {
            return;
        }

        items.push({
            id: `catalog-track-${track.videoId}-${position}`,
            group: 'Tracks',
            label: track.title,
            sub: track.artists,
            hint: track.duration ?? undefined,
            artwork: track.thumbnailUrl,
            action: trans('Queue next'),
            run: () => queueNext(track, position),
            play: () => playNow(track),
            previewHint: trackHint,
        });
    });

    results.albums.forEach((album) => {
        items.push({
            id: `catalog-album-${album.id}`,
            group: 'Albums',
            label: album.title,
            sub: [
                album.type,
                album.year,
                album.trackCount
                    ? transChoice(
                          ':count track|:count tracks',
                          album.trackCount,
                      )
                    : null,
            ]
                .filter(Boolean)
                .join(' · '),
            artwork: album.thumbnailUrl,
            action: trans('Open'),
            run: () => openAlbum(album),
        });
    });

    results.artists
        .filter((artist) => artist.id !== top?.id)
        .forEach((artist) => {
            items.push({
                id: `catalog-artist-${artist.id}`,
                group: 'Artists',
                label: artist.name,
                sub: artist.subscribers
                    ? trans(':count subscribers', { count: artist.subscribers })
                    : trans('Artist'),
                artwork: artist.thumbnailUrl,
                round: true,
                action: trans('Open'),
                run: () => openArtist(artist),
                previewHint: openHint,
            });
        });

    results.playlists.forEach((playlist) => {
        items.push({
            id: `catalog-playlist-${playlist.id}`,
            group: 'Playlists',
            label: playlist.title,
            sub: [
                playlist.author,
                playlist.trackCount
                    ? transChoice(
                          ':count track|:count tracks',
                          playlist.trackCount,
                      )
                    : null,
            ]
                .filter(Boolean)
                .join(' · '),
            artwork: playlist.thumbnailUrl,
            action: trans('Open'),
            run: () => router.visit(BrowsePlaylistController.show(playlist.id)),
            previewHint: openHint,
        });
    });

    return items;
});

const items = computed<Item[]>(() => {
    const openHint = trans('Enter to open');

    const playlists: Item[] = (page.props.library?.playlists ?? []).map(
        (playlist) => ({
            id: `playlist-${playlist.id}`,
            group: isTyping.value ? 'In your library' : 'Playlists',
            label: playlist.title,
            sub: playlist.trackCount
                ? transChoice(':count track|:count tracks', playlist.trackCount)
                : undefined,
            artwork: playlist.thumbnailUrl,
            action: trans('Open'),
            run: () => router.visit(PlaylistController.show(playlist.id)),
            previewHint: openHint,
        }),
    );

    const upNext: Item[] = player.upNext.value.map((track, offset) => ({
        id: `track-${track.key}`,
        group: 'Up next',
        label: track.title,
        sub: track.artists,
        artwork: track.thumbnailUrl,
        action: trans('Play'),
        run: () => player.jumpTo(player.state.index + 1 + offset),
    }));

    const pages: Item[] = [
        {
            id: 'go-discover',
            group: 'Go to',
            label: trans('Discover'),
            hint: 'G',
            icon: Sparkles,
            action: trans('Open'),
            run: () => router.visit(DiscoverController.index()),
        },
        {
            id: 'go-library',
            group: 'Go to',
            label: trans('Library'),
            hint: 'L',
            icon: SquareLibrary,
            action: trans('Open'),
            run: () => router.visit(PlaylistController.index()),
        },
        {
            id: 'go-connection',
            group: 'Go to',
            label: trans('YouTube Music connection'),
            icon: Cable,
            action: trans('Open'),
            run: () => router.visit(YouTubeMusicConnectionController.create()),
        },
        {
            id: 'go-settings',
            group: 'Go to',
            label: trans('Settings'),
            icon: Settings,
            action: trans('Open'),
            run: () => router.visit(UserProfileController.edit()),
        },
    ];

    const actions: Item[] = [
        {
            id: 'toggle-play',
            group: 'Actions',
            label: player.state.playing ? trans('Pause') : trans('Play'),
            hint: trans('Space'),
            icon: Pause,
            action: trans('Run'),
            run: player.toggle,
        },
        {
            id: 'toggle-queue',
            group: 'Actions',
            label: player.state.queueOpen
                ? trans('Hide up next')
                : trans('Show up next'),
            hint: 'Q',
            icon: ListMusic,
            action: trans('Run'),
            run: player.toggleQueue,
        },
        {
            id: 'toggle-sidebar',
            group: 'Actions',
            label: collapsed.value
                ? trans('Expand sidebar')
                : trans('Collapse sidebar'),
            hint: '[',
            icon: PanelLeft,
            action: trans('Run'),
            run: () => (collapsed.value = !collapsed.value),
        },
    ];

    if (!isTyping.value) {
        return [
            recent.items.value.map(recentItem),
            ...[playlists, upNext, pages, actions].map((group) =>
                group.slice(0, MAX_PER_GROUP),
            ),
        ].flat();
    }

    const lowered = needle.value.toLowerCase();
    const matches = (item: Item) => item.label.toLowerCase().includes(lowered);

    return [
        ...[playlists, upNext].flatMap((group) =>
            group.filter(matches).slice(0, MAX_PER_GROUP),
        ),
        ...catalogItems.value,
        ...[pages, actions].flatMap((group) =>
            group.filter(matches).slice(0, MAX_PER_GROUP),
        ),
    ];
});

const {
    selected,
    select,
    move: moveSelection,
} = useListSelection(items, query);

const selectedItem = computed<Item | undefined>(
    () => items.value[selected.value],
);

/** Groups in order, with the catalog's loading or failure after the library. */
const sections = computed<Section[]>(() => {
    const byGroup = new Map<Group, { item: Item; index: number }[]>();

    items.value.forEach((item, index) => {
        byGroup.set(item.group, [
            ...(byGroup.get(item.group) ?? []),
            { item, index },
        ]);
    });

    const groups: Section[] = [...byGroup.entries()].map(
        ([group, entries]) => ({ type: 'group', group, entries }),
    );
    const libraryCount = groups.filter(
        (section) =>
            section.type === 'group' && LIBRARY_GROUPS.includes(section.group),
    ).length;

    return [
        ...groups.slice(0, libraryCount),
        { type: 'catalog-status' },
        ...groups.slice(libraryCount),
    ];
});

const catalogLoading = computed(
    () =>
        searchesCatalog.value &&
        suggestions.loading.value &&
        suggestions.results.value === null,
);
const catalogFailed = computed(
    () => searchesCatalog.value && suggestions.failed.value,
);

watch(palette.open, (open) => {
    if (open) {
        query.value = '';
        select(0);
    } else {
        suggestions.cancel();
    }
});

let pointer = { x: -1, y: -1 };

/** Hover selects only when the pointer moves, not when rows move under it. */
function hover(event: MouseEvent, index: number): void {
    if (event.screenX === pointer.x && event.screenY === pointer.y) {
        return;
    }

    pointer = { x: event.screenX, y: event.screenY };
    select(index);
}

async function move(delta: number): Promise<void> {
    moveSelection(delta);
    await nextTick();
    list.value
        ?.querySelector('[data-selected="true"]')
        ?.scrollIntoView({ block: 'nearest' });
}

function run(item: Item | undefined): void {
    if (!item) {
        return;
    }

    if (!item.keepOpen) {
        palette.hide();
    }

    item.run();
}

function play(item: Item): void {
    if (item.play) {
        palette.hide();
        item.play();
    }
}

function onEnter(event: KeyboardEvent): void {
    // Enter on a focused button (the footer's "All results") is its own.
    if (event.isComposing || !(event.target instanceof HTMLInputElement)) {
        return;
    }

    event.preventDefault();

    if (event.metaKey || event.ctrlKey) {
        searchAll();

        return;
    }

    const item = selectedItem.value;

    if (event.shiftKey && item?.play) {
        play(item);

        return;
    }

    run(item);
}
</script>

<template>
    <DialogRoot v-model:open="palette.open.value">
        <DialogPortal>
            <DialogOverlay
                class="palette-overlay fixed inset-0 z-50 bg-[oklch(0.1_0.01_60/0.55)] backdrop-blur-[3px]"
            />
            <DialogContent
                class="material-glass palette fixed top-[12vh] left-1/2 z-50 -translate-x-1/2 overflow-hidden rounded-[14px] transition-[width] duration-[220ms] ease-out-quint focus:outline-none"
                :class="
                    isTyping
                        ? 'w-[min(880px,calc(100%-32px))]'
                        : 'w-[min(620px,calc(100%-32px))]'
                "
            >
                <!-- On a wrapper: reka binds DialogContent's listeners twice. -->
                <div
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter="onEnter"
                >
                    <DialogTitle class="sr-only">{{
                        $t('Search Sonder')
                    }}</DialogTitle>
                    <DialogDescription class="sr-only">{{
                        $t(
                            'Search YouTube Music, or jump to a playlist, a queued track, a page or an action.',
                        )
                    }}</DialogDescription>
                    <div
                        class="flex items-center gap-2.5 border-b border-line px-4"
                    >
                        <Search class="size-4 shrink-0 text-faint" />
                        <input
                            v-model="query"
                            class="w-full bg-transparent py-[15px] text-base text-paper placeholder:text-faint focus:outline-none"
                            :placeholder="
                                $t('Search an artist, an album, a track…')
                            "
                            :aria-label="$t('Search')"
                            autocomplete="off"
                        />
                    </div>
                    <div
                        class="grid grid-cols-[minmax(0,1fr)]"
                        :class="
                            isTyping &&
                            'min-[860px]:grid-cols-[minmax(0,1fr)_300px]'
                        "
                    >
                        <div
                            ref="list"
                            class="max-h-[440px] overflow-y-auto p-1.5"
                            role="listbox"
                            :aria-busy="catalogLoading"
                        >
                            <template
                                v-for="section in sections"
                                :key="
                                    section.type === 'group'
                                        ? section.group
                                        : section.type
                                "
                            >
                                <template v-if="section.type === 'group'">
                                    <p
                                        class="px-2.5 pt-2.5 pb-1 text-xs font-bold text-faint"
                                    >
                                        {{ $t(section.group) }}
                                    </p>
                                    <div
                                        v-for="{
                                            item,
                                            index,
                                        } in section.entries"
                                        :key="item.id"
                                        role="option"
                                        :aria-selected="index === selected"
                                        :data-selected="index === selected"
                                        class="group/item flex w-full cursor-pointer items-center text-left"
                                        :class="[
                                            item.group === 'Top result'
                                                ? 'gap-3.5 rounded-[10px] p-2.5'
                                                : 'gap-2.5 rounded-lg px-2.5 py-[7px]',
                                            index === selected
                                                ? 'bg-raised'
                                                : 'hover:bg-hover',
                                        ]"
                                        @mousemove="hover($event, index)"
                                        @click="run(item)"
                                    >
                                        <Artwork
                                            v-if="item.artwork !== undefined"
                                            :src="item.artwork"
                                            class="shrink-0"
                                            :class="[
                                                item.group === 'Top result'
                                                    ? 'size-[52px]'
                                                    : 'size-[30px]',
                                                item.round
                                                    ? 'rounded-full'
                                                    : 'rounded',
                                            ]"
                                        />
                                        <span
                                            v-else-if="item.icon"
                                            class="grid w-[30px] shrink-0 place-items-center"
                                        >
                                            <component
                                                :is="item.icon"
                                                class="size-4 text-faint"
                                            />
                                        </span>
                                        <span
                                            class="flex min-w-0 flex-1 flex-col leading-[1.3]"
                                        >
                                            <b
                                                class="truncate"
                                                :class="
                                                    item.group === 'Top result'
                                                        ? 'text-[17px] font-extrabold tracking-[-0.01em]'
                                                        : 'font-semibold'
                                                "
                                                >{{ item.label }}</b
                                            >
                                            <small
                                                v-if="item.sub"
                                                class="truncate text-[12.5px] font-medium text-faint"
                                                >{{ item.sub }}</small
                                            >
                                        </span>
                                        <button
                                            v-if="item.remove"
                                            type="button"
                                            tabindex="-1"
                                            class="grid size-6 place-items-center rounded text-faint opacity-0 group-hover/item:opacity-100 hover:text-paper"
                                            :class="
                                                index === selected &&
                                                'opacity-100'
                                            "
                                            :aria-label="
                                                $t('Remove from recent')
                                            "
                                            :title="$t('Remove from recent')"
                                            @click.stop="item.remove()"
                                        >
                                            <X class="size-3.5" />
                                        </button>
                                        <button
                                            v-if="item.play"
                                            type="button"
                                            tabindex="-1"
                                            class="accent-fill shrink-0 place-items-center rounded-full"
                                            :class="
                                                item.group === 'Top result'
                                                    ? 'grid size-8'
                                                    : index === selected
                                                      ? 'grid size-[26px]'
                                                      : 'hidden size-[26px]'
                                            "
                                            :aria-label="$t('Play now')"
                                            :title="$t('Play now')"
                                            @click.stop="play(item)"
                                        >
                                            <Play
                                                class="fill-current"
                                                :class="
                                                    item.group === 'Top result'
                                                        ? 'size-3.5'
                                                        : 'size-3'
                                                "
                                            />
                                        </button>
                                        <kbd
                                            v-if="
                                                item.hint &&
                                                (item.group === 'Go to' ||
                                                    item.group === 'Actions')
                                            "
                                            class="keycap"
                                            >{{ item.hint }}</kbd
                                        >
                                        <span
                                            v-else-if="item.hint"
                                            class="text-xs font-medium text-faint tabular-nums"
                                            >{{ item.hint }}</span
                                        >
                                    </div>
                                </template>
                                <template v-else-if="catalogFailed">
                                    <p
                                        class="px-2.5 pt-2.5 pb-1 text-xs font-bold text-faint"
                                    >
                                        {{ $t('YouTube Music') }}
                                    </p>
                                    <p
                                        class="px-2.5 py-2 text-sm text-faint"
                                        role="status"
                                    >
                                        {{ $t('Catalog unavailable') }}
                                    </p>
                                </template>
                                <div
                                    v-else-if="catalogLoading"
                                    :aria-label="$t('Loading')"
                                >
                                    <p
                                        class="px-2.5 pt-2.5 pb-1 text-xs font-bold text-faint"
                                    >
                                        {{ $t('Top result') }}
                                    </p>
                                    <div
                                        class="flex items-center gap-3.5 p-2.5"
                                    >
                                        <span
                                            class="skeleton size-[52px] shrink-0 rounded-full"
                                        />
                                        <span
                                            class="flex flex-1 flex-col gap-2"
                                        >
                                            <span class="skeleton h-3.5 w-40" />
                                            <span class="skeleton h-2.5 w-24" />
                                        </span>
                                    </div>
                                    <p
                                        class="px-2.5 pt-2.5 pb-1 text-xs font-bold text-faint"
                                    >
                                        {{ $t('Tracks') }}
                                    </p>
                                    <div
                                        v-for="row in 3"
                                        :key="row"
                                        class="flex items-center gap-2.5 px-2.5 py-[7px]"
                                    >
                                        <span
                                            class="skeleton size-[30px] shrink-0 rounded"
                                        />
                                        <span
                                            class="flex flex-1 flex-col gap-1.5"
                                        >
                                            <span
                                                class="skeleton h-3"
                                                :style="{
                                                    width: `${70 - row * 12}%`,
                                                }"
                                            />
                                            <span class="skeleton h-2.5 w-20" />
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <p
                                v-if="
                                    !items.length &&
                                    !catalogLoading &&
                                    !catalogFailed
                                "
                                class="px-2.5 py-6 text-center text-sm text-faint"
                            >
                                {{ $t('Nothing matches “:query”.', { query }) }}
                            </p>
                        </div>
                        <PalettePreview
                            v-if="isTyping && selectedItem"
                            class="hidden min-[860px]:block"
                            :label="selectedItem.label"
                            :sub="selectedItem.sub"
                            :artwork="selectedItem.artwork"
                            :icon="selectedItem.icon"
                            :round="selectedItem.round"
                            :tracks="selectedItem.previewTracks"
                            :hint="selectedItem.previewHint"
                        />
                    </div>
                    <div
                        class="flex items-center gap-4 border-t border-line px-3.5 py-2 text-xs font-medium text-faint"
                    >
                        <span class="inline-flex items-center gap-1.5"
                            ><kbd class="keycap">↑</kbd
                            ><kbd class="keycap">↓</kbd>
                            {{ $t('Navigate') }}</span
                        >
                        <span
                            v-if="selectedItem"
                            class="inline-flex items-center gap-1.5"
                            ><kbd class="keycap"
                                ><CornerDownLeft class="size-3"
                            /></kbd>
                            {{ selectedItem.action }}</span
                        >
                        <span
                            v-if="selectedItem?.play"
                            class="hidden items-center gap-1.5 sm:inline-flex"
                            ><kbd class="keycap">⇧</kbd
                            ><kbd class="keycap"
                                ><CornerDownLeft class="size-3"
                            /></kbd>
                            {{ $t('Play now') }}</span
                        >
                        <button
                            v-if="searchesCatalog"
                            type="button"
                            class="ml-auto inline-flex min-w-0 items-center gap-1.5 font-semibold text-dim hover:text-paper"
                            @click="searchAll"
                        >
                            <span class="truncate">{{
                                $t('All results for “:query”', {
                                    query: needle,
                                })
                            }}</span>
                            <kbd class="keycap">{{ modKey }}</kbd
                            ><kbd class="keycap"
                                ><CornerDownLeft class="size-3"
                            /></kbd>
                        </button>
                    </div>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style scoped>
.palette-overlay[data-state='open'] {
    animation: fade-in 160ms ease-out;
}

.palette[data-state='open'] {
    animation: palette-in 220ms var(--ease-out-quint);
}

@keyframes fade-in {
    from {
        opacity: 0;
    }
}

@keyframes palette-in {
    from {
        opacity: 0;
        transform: translate(-50%, -8px) scale(0.98);
    }
}
</style>
