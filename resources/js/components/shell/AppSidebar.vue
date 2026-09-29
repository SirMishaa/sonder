<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { useLocalStorage } from '@vueuse/core';
import {
    BarChart3,
    ListFilter,
    PanelLeftClose,
    Search,
    Sparkles,
    SquareLibrary,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import SonderMark from '@/components/brand/SonderMark.vue';
import SonderWordmark from '@/components/brand/SonderWordmark.vue';
import Artwork from '@/components/music/Artwork.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import Waveform from '@/components/music/Waveform.vue';
import SyncStatus from '@/components/shell/SyncStatus.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInitials } from '@/composables/useInitials';
import { usePalette } from '@/composables/usePalette';
import { usePlayer } from '@/composables/usePlayer';
import { useShortcuts } from '@/composables/useShortcuts';
import { useToast } from '@/composables/useToast';

const page = usePage();
const player = usePlayer();
const palette = usePalette();
const { toast } = useToast();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { getInitials } = useInitials();

const collapsed = useLocalStorage('sonder.sidebar.collapsed', false);

const library = computed(() => page.props.library);
const user = computed(() => page.props.auth.user);

const RECENT_CHANGE_DAYS = 3;

const filterOpen = ref(false);
const filter = ref('');
const filterInput = ref<HTMLInputElement | null>(null);

const playlists = computed(() => {
    const all = library.value?.playlists ?? [];
    const query = filter.value.trim().toLowerCase();

    return query
        ? all.filter((playlist) => playlist.title.toLowerCase().includes(query))
        : all;
});

function changedRecently(playlist: App.Data.LibraryPlaylistData): boolean {
    if (!playlist.lastChangedAt) {
        return false;
    }

    return (
        Date.now() - new Date(playlist.lastChangedAt).getTime() <
        RECENT_CHANGE_DAYS * 86_400_000
    );
}

const nav = [
    {
        label: 'Discover',
        href: DiscoverController.index(),
        icon: Sparkles,
        key: 'G',
        exact: true,
    },
    {
        label: 'Library',
        href: PlaylistController.index(),
        icon: SquareLibrary,
        key: 'L',
        exact: true,
    },
];

const navEl = ref<HTMLElement | null>(null);
const pill = ref({ top: 0, height: 0, visible: false });

function placePill(): void {
    const active = navEl.value?.querySelector<HTMLElement>(
        '[data-active="true"]',
    );

    pill.value = active
        ? { top: active.offsetTop, height: active.offsetHeight, visible: true }
        : { ...pill.value, visible: false };
}

onMounted(placePill);
watch(
    () => page.url,
    () => nextTick(placePill),
);
watch(collapsed, () => setTimeout(placePill, 260));

async function toggleFilter(): Promise<void> {
    filterOpen.value = !filterOpen.value;

    if (!filterOpen.value) {
        filter.value = '';

        return;
    }

    await nextTick();
    filterInput.value?.focus();
}

useShortcuts({
    '[': () => {
        collapsed.value = !collapsed.value;
    },
});
</script>

<template>
    <aside
        class="material-sleeve flex min-h-0 flex-col overflow-hidden border-r border-line transition-[width] duration-[240ms] ease-out-quint"
        :class="collapsed ? 'w-[68px]' : 'w-[264px]'"
        :aria-label="$t('Sidebar')"
    >
        <div class="relative isolate pb-3">
            <div class="sidetop-glow" aria-hidden="true" />

            <div
                class="flex items-center gap-2.5"
                :class="
                    collapsed ? 'flex-col px-0 pt-4 pb-3' : 'py-4 pr-3.5 pl-4'
                "
            >
                <Link
                    :href="DiscoverController.index()"
                    class="flex items-center gap-2.5 rounded-lg"
                    :aria-label="$t('Sonder, go to Discover')"
                >
                    <SonderMark
                        :spinning="player.state.playing"
                        class="size-10 shrink-0"
                    />
                    <SonderWordmark v-if="!collapsed" />
                </Link>
                <button
                    type="button"
                    class="grid size-7 place-items-center rounded-md text-faint transition-colors hover:bg-raised hover:text-paper"
                    :class="{ 'ml-auto': !collapsed }"
                    :aria-label="
                        collapsed
                            ? $t('Expand sidebar')
                            : $t('Collapse sidebar')
                    "
                    :title="$t('Toggle sidebar  [')"
                    @click="collapsed = !collapsed"
                >
                    <PanelLeftClose
                        class="size-[18px] transition-transform duration-[240ms] ease-out-quint"
                        :class="{ 'rotate-180': collapsed }"
                    />
                </button>
            </div>

            <button
                type="button"
                class="mx-2.5 mb-2.5 flex items-center gap-2.5 rounded-[9px] border border-white/8 bg-[oklch(0.14_0.01_60/0.55)] py-[7px] text-faint backdrop-blur-sm transition-colors hover:border-white/15"
                :class="
                    collapsed
                        ? 'w-[calc(100%-20px)] justify-center'
                        : 'w-[calc(100%-20px)] px-2.5'
                "
                :title="$t('Search  ⌘K')"
                @click="palette.show"
            >
                <Search class="size-4 shrink-0" />
                <template v-if="!collapsed">
                    <span>{{ $t('Search') }}</span>
                    <kbd class="keycap ml-auto">⌘K</kbd>
                </template>
            </button>

            <nav
                ref="navEl"
                class="relative flex flex-col gap-0.5 px-2.5"
                :aria-label="$t('Main')"
            >
                <span
                    class="nav-pill"
                    :style="{
                        transform: `translateY(${pill.top}px)`,
                        height: `${pill.height}px`,
                        opacity: pill.visible ? 1 : 0,
                    }"
                    aria-hidden="true"
                />
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="item.href"
                    :data-active="isCurrentUrl(item.href)"
                    class="nav-item"
                    :class="{ 'justify-center px-0': collapsed }"
                    :title="`${$t(item.label)}  ${item.key}`"
                >
                    <component :is="item.icon" class="size-[18px] shrink-0" />
                    <span v-if="!collapsed">{{ $t(item.label) }}</span>
                </Link>
                <button
                    type="button"
                    class="nav-item"
                    :class="{ 'justify-center px-0': collapsed }"
                    :title="$t('Stats are coming later')"
                    @click="toast($t('Stats are coming later.'))"
                >
                    <BarChart3 class="size-[18px] shrink-0" />
                    <template v-if="!collapsed">
                        <span>{{ $t('Stats') }}</span>
                        <span
                            class="ml-auto text-[11px] font-medium text-faint"
                            >{{ $t('soon') }}</span
                        >
                    </template>
                </button>
            </nav>
        </div>

        <div
            class="flex items-center gap-2 text-[12.5px] font-bold text-dim"
            :class="collapsed ? 'justify-center pt-4 pb-2' : 'px-5 pt-4 pb-2'"
        >
            <template v-if="!collapsed">
                <span>{{ $t('Playlists') }}</span>
                <span class="font-medium text-faint">{{
                    library?.playlists.length ?? 0
                }}</span>
                <button
                    type="button"
                    class="ml-auto grid size-6 place-items-center rounded-md text-faint hover:bg-raised hover:text-paper"
                    :aria-label="
                        filterOpen ? $t('Clear filter') : $t('Filter playlists')
                    "
                    @click="toggleFilter"
                >
                    <X v-if="filterOpen" class="size-[15px]" />
                    <ListFilter v-else class="size-[15px]" />
                </button>
            </template>
            <span v-else class="h-px w-6 bg-line" />
        </div>

        <div v-if="filterOpen && !collapsed" class="px-3 pb-2">
            <input
                ref="filterInput"
                v-model="filter"
                type="search"
                :placeholder="$t($t('Filter playlists'))"
                class="w-full rounded-lg border border-line bg-ink px-2.5 py-1.5 text-sm text-paper placeholder:text-faint focus:border-amber/50 focus:outline-none"
                @keydown.esc="toggleFilter"
            />
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-2.5 pb-3">
            <Link
                v-for="playlist in playlists"
                :key="playlist.id"
                :href="PlaylistController.show(playlist.id)"
                class="playlist-item group"
                :class="{
                    'is-active': isCurrentOrParentUrl(
                        PlaylistController.show(playlist.id),
                    ),
                    'is-playing': player.isPlayingFrom(playlist.id),
                    'justify-center': collapsed,
                }"
                :title="playlist.title"
            >
                <Artwork
                    :src="playlist.thumbnailUrl"
                    class="size-[34px] shrink-0 rounded-[5px]"
                />
                <template v-if="!collapsed">
                    <Waveform
                        v-if="player.isPlayingFrom(playlist.id)"
                        :seed="playlist.title"
                        :bars="40"
                        :playing="player.state.playing"
                        :strength="0.24"
                        class="absolute inset-y-1.5 right-2 left-[46px] -z-10"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-sm font-semibold"
                        :class="{
                            'text-faint line-through decoration-alarm/60':
                                playlist.isRemoved,
                        }"
                    >
                        {{ playlist.title }}
                    </span>
                    <Equalizer
                        v-if="player.isPlayingFrom(playlist.id)"
                        :playing="player.state.playing"
                    />
                    <span
                        v-else-if="playlist.isRemoved"
                        class="size-1.5 shrink-0 rounded-full bg-alarm"
                        :title="$t('No longer in your YouTube Music library')"
                    />
                    <span
                        v-else-if="changedRecently(playlist)"
                        class="size-1.5 shrink-0 rounded-full bg-amber"
                        :title="$t('Changed in the last few days')"
                    />
                </template>
            </Link>
            <p
                v-if="filter && playlists.length === 0"
                class="px-2 py-3 text-sm text-faint"
            >
                {{ $t('No playlist matches “:query”.', { query: filter }) }}
            </p>
        </div>

        <div
            class="flex items-center gap-2.5 border-t border-line"
            :class="collapsed ? 'flex-col py-3' : 'px-3 py-2.5'"
        >
            <DropdownMenu>
                <DropdownMenuTrigger
                    class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg p-0.5 text-left hover:bg-white/4"
                    :class="{ 'justify-center': collapsed }"
                    data-test="sidebar-menu-button"
                >
                    <span
                        class="grid size-[30px] shrink-0 place-items-center rounded-full bg-raised text-[13px] font-bold"
                    >
                        {{ getInitials(user.name) }}
                    </span>
                    <span v-if="!collapsed" class="min-w-0">
                        <span
                            class="block truncate text-[13.5px] font-semibold"
                        >
                            {{ library?.accountName ?? user.name }}
                        </span>
                        <SyncStatus v-if="library" />
                    </span>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="min-w-56 rounded-lg"
                    side="top"
                    align="start"
                    :side-offset="8"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </aside>
</template>

<style scoped>
.sidetop-glow {
    position: absolute;
    inset: 0 0 -160px 0;
    z-index: -2;
    pointer-events: none;
    background:
        radial-gradient(
            120% 70% at 12% 0%,
            oklch(0.8 0.15 68 / 0.3),
            transparent 62%
        ),
        radial-gradient(
            90% 60% at 95% 10%,
            oklch(0.66 0.17 48 / 0.16),
            transparent 70%
        );
    mask-image: linear-gradient(
        180deg,
        rgb(0 0 0 / 0.9) 0%,
        rgb(0 0 0 / 0.5) 45%,
        transparent 100%
    );
}

.nav-pill {
    position: absolute;
    left: 10px;
    right: 10px;
    top: 0;
    border-radius: 9px;
    background: linear-gradient(
        90deg,
        oklch(0.8 0.15 68 / 0.16),
        oklch(0.235 0.014 60 / 0.7)
    );
    box-shadow: inset 0 0 0 1px oklch(0.8 0.15 68 / 0.22);
    transition:
        transform 240ms var(--ease-out-quint),
        height 240ms var(--ease-out-quint),
        opacity 140ms;
}

.nav-item {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 7px 10px;
    border-radius: 9px;
    color: var(--color-dim);
    font-weight: 600;
    transition:
        color 140ms,
        background 140ms;
}

.nav-item:hover {
    color: var(--color-paper);
    background: oklch(1 0 0 / 0.04);
}

.nav-item[data-active='true'] {
    color: var(--color-paper);
}

.nav-item[data-active='true'] svg {
    color: var(--color-amber);
}

.playlist-item {
    position: relative;
    isolation: isolate;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 5px 6px;
    border-radius: 8px;
    transition: background 140ms;
}

.playlist-item:hover {
    background: var(--color-hover);
}

.playlist-item.is-active {
    background: var(--color-raised);
    box-shadow: inset 0 0 0 1px var(--color-line);
}

.playlist-item.is-playing {
    background: linear-gradient(
        90deg,
        oklch(0.8 0.15 68 / 0.08),
        transparent 70%
    );
}

.playlist-item.is-playing.is-active {
    background: linear-gradient(
        90deg,
        oklch(0.8 0.15 68 / 0.1),
        var(--color-raised) 70%
    );
}
</style>
