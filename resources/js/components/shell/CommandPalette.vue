<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useLocalStorage } from '@vueuse/core';
import { trans, transChoice } from 'laravel-vue-i18n';
import {
    Cable,
    ListMusic,
    PanelLeft,
    Pause,
    Settings,
    Sparkles,
    SquareLibrary,
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
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import UserProfileController from '@/actions/App/Http/Controllers/UserProfileController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Artwork from '@/components/music/Artwork.vue';
import { usePalette } from '@/composables/usePalette';
import { usePlayer } from '@/composables/usePlayer';

type Item = {
    id: string;
    group: 'Playlists' | 'Up next' | 'Go to' | 'Actions';
    label: string;
    hint?: string;
    artwork?: string | null;
    icon?: Component;
    run: () => void;
};

const page = usePage();
const palette = usePalette();
const player = usePlayer();
const collapsed = useLocalStorage('sonder.sidebar.collapsed', false);

const query = ref('');
const selected = ref(0);
const list = ref<HTMLElement | null>(null);

const MAX_PER_GROUP = 6;

const items = computed<Item[]>(() => {
    const playlists: Item[] = (page.props.library?.playlists ?? []).map(
        (playlist) => ({
            id: `playlist-${playlist.id}`,
            group: 'Playlists',
            label: playlist.title,
            hint: playlist.trackCount
                ? transChoice(':count track|:count tracks', playlist.trackCount)
                : undefined,
            artwork: playlist.thumbnailUrl,
            run: () => router.visit(PlaylistController.show(playlist.id)),
        }),
    );

    const upNext: Item[] = player.upNext.value.map((track, offset) => ({
        id: `track-${track.key}`,
        group: 'Up next',
        label: `${track.title} · ${track.artists}`,
        artwork: track.thumbnailUrl,
        run: () => player.jumpTo(player.state.index + 1 + offset),
    }));

    const pages: Item[] = [
        {
            id: 'go-discover',
            group: 'Go to',
            label: trans('Discover'),
            hint: 'G',
            icon: Sparkles,
            run: () => router.visit(DiscoverController.index()),
        },
        {
            id: 'go-library',
            group: 'Go to',
            label: trans('Library'),
            hint: 'L',
            icon: SquareLibrary,
            run: () => router.visit(PlaylistController.index()),
        },
        {
            id: 'go-connection',
            group: 'Go to',
            label: trans('YouTube Music connection'),
            icon: Cable,
            run: () => router.visit(YouTubeMusicConnectionController.create()),
        },
        {
            id: 'go-settings',
            group: 'Go to',
            label: trans('Settings'),
            icon: Settings,
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
            run: () => (collapsed.value = !collapsed.value),
        },
    ];

    const needle = query.value.trim().toLowerCase();
    const matches = (item: Item) =>
        !needle || item.label.toLowerCase().includes(needle);

    return [playlists, upNext, pages, actions].flatMap((group) =>
        group.filter(matches).slice(0, MAX_PER_GROUP),
    );
});

const groups = computed(() => {
    const byGroup = new Map<string, { item: Item; index: number }[]>();

    items.value.forEach((item, index) => {
        byGroup.set(item.group, [
            ...(byGroup.get(item.group) ?? []),
            { item, index },
        ]);
    });

    return [...byGroup.entries()];
});

watch(query, () => {
    selected.value = 0;
});

watch(palette.open, (open) => {
    if (open) {
        query.value = '';
        selected.value = 0;
    }
});

async function move(delta: number): Promise<void> {
    if (!items.value.length) {
        return;
    }

    selected.value =
        (selected.value + delta + items.value.length) % items.value.length;
    await nextTick();
    list.value
        ?.querySelector('[data-selected="true"]')
        ?.scrollIntoView({ block: 'nearest' });
}

function run(item: Item | undefined): void {
    if (!item) {
        return;
    }

    palette.hide();
    item.run();
}
</script>

<template>
    <DialogRoot v-model:open="palette.open.value">
        <DialogPortal>
            <DialogOverlay
                class="palette-overlay fixed inset-0 z-50 bg-[oklch(0.1_0.01_60/0.55)] backdrop-blur-[3px]"
            />
            <DialogContent
                class="material-glass palette fixed top-[14vh] left-1/2 z-50 w-[min(620px,calc(100%-32px))] -translate-x-1/2 overflow-hidden rounded-[14px] focus:outline-none"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="run(items[selected])"
            >
                <DialogTitle class="sr-only">{{
                    $t('Search Sonder')
                }}</DialogTitle>
                <DialogDescription class="sr-only">{{
                    $t(
                        'Jump to a playlist, a queued track, a page or an action.',
                    )
                }}</DialogDescription>
                <input
                    v-model="query"
                    class="w-full border-b border-line bg-transparent px-[18px] py-4 text-base text-paper placeholder:text-faint focus:outline-none"
                    :placeholder="$t('Search playlists, tracks, actions…')"
                    :aria-label="$t('Search')"
                    autocomplete="off"
                />
                <div
                    ref="list"
                    class="max-h-[360px] overflow-y-auto p-1.5"
                    role="listbox"
                >
                    <template v-for="[group, entries] in groups" :key="group">
                        <p
                            class="px-2.5 pt-2 pb-1 text-xs font-bold text-faint"
                        >
                            {{ $t(group) }}
                        </p>
                        <button
                            v-for="{ item, index } in entries"
                            :key="item.id"
                            type="button"
                            role="option"
                            :aria-selected="index === selected"
                            :data-selected="index === selected"
                            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left"
                            :class="
                                index === selected
                                    ? 'bg-raised'
                                    : 'hover:bg-hover'
                            "
                            @mouseenter="selected = index"
                            @click="run(item)"
                        >
                            <Artwork
                                v-if="item.artwork !== undefined"
                                :src="item.artwork"
                                class="size-[26px] shrink-0 rounded"
                            />
                            <component
                                :is="item.icon"
                                v-else-if="item.icon"
                                class="size-4 shrink-0 text-faint"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                item.label
                            }}</span>
                            <kbd
                                v-if="item.hint && item.group !== 'Playlists'"
                                class="keycap"
                                >{{ item.hint }}</kbd
                            >
                            <span
                                v-else-if="item.hint"
                                class="text-xs font-medium text-faint"
                                >{{ item.hint }}</span
                            >
                        </button>
                    </template>
                    <p
                        v-if="!items.length"
                        class="px-2.5 py-6 text-center text-sm text-faint"
                    >
                        {{ $t('Nothing matches “:query”.', { query }) }}
                    </p>
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
