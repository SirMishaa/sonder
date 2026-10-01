<script setup lang="ts">
import { ArrowDownUp, ChevronsUpDown, Play, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import PlayableArtwork from '@/components/music/PlayableArtwork.vue';
import TrackMiniRow from '@/components/music/TrackMiniRow.vue';
import PlayerDebugPanel from '@/components/shell/PlayerDebugPanel.vue';
import { useDragSort } from '@/composables/useDragSort';
import { usePlayer } from '@/composables/usePlayer';
import { useQueuePick } from '@/composables/useQueuePick';

const player = usePlayer();
const { pick } = useQueuePick();

const VISIBLE_UP_NEXT = 40;

const debugOpen = ref(false);

const list = ref<HTMLElement | null>(null);
const listFocused = ref(false);
const selected = ref(-1);

const visible = computed(() => player.upNext.value.slice(0, VISIBLE_UP_NEXT));

const { grab, styleFor, isSorting, isGrabbed } = useDragSort((from, to) => {
    player.moveUpcoming(from, to);
    selected.value = to;
});

function select(event: MouseEvent, offset: number): void {
    pick(event, offset);
    selected.value = 0;
}

function remove(offset: number): void {
    const track = visible.value[offset];

    if (track) {
        player.unqueue(track.key);
        selected.value = Math.min(selected.value, visible.value.length - 1);
    }
}

function moveSelection(delta: number): void {
    if (visible.value.length === 0) {
        return;
    }

    selected.value = Math.max(
        0,
        Math.min(visible.value.length - 1, selected.value + delta),
    );
}

function shiftSelected(delta: number): void {
    const from = selected.value;
    const to = from + delta;

    if (from < 0 || to < 0 || to >= visible.value.length) {
        return;
    }

    player.moveUpcoming(from, to);
    selected.value = to;
}

function playSelected(): void {
    if (selected.value >= 0) {
        player.jumpTo(player.state.index + 1 + selected.value);
        selected.value = 0;
    }
}

const HINTS = [
    { keys: ['J', 'K'], icon: ChevronsUpDown, label: 'move' },
    { keys: ['⇧', 'J', 'K'], icon: ArrowDownUp, label: 'reorder' },
    { keys: ['⏎'], icon: Play, label: 'play' },
    { keys: ['X'], icon: Trash2, label: 'remove' },
];

const SHORTCUTS: Record<string, () => void> = {
    j: () => moveSelection(1),
    arrowdown: () => moveSelection(1),
    k: () => moveSelection(-1),
    arrowup: () => moveSelection(-1),
    enter: playSelected,
    x: () => remove(selected.value),
    delete: () => remove(selected.value),
    backspace: () => remove(selected.value),
};

const SHIFTED_SHORTCUTS: Record<string, () => void> = {
    j: () => shiftSelected(1),
    arrowdown: () => shiftSelected(1),
    k: () => shiftSelected(-1),
    arrowup: () => shiftSelected(-1),
};

/**
 * Keys act on the list only while it has focus, so J/K here never moves the
 * suggestions block on the same page.
 */
function onKeydown(event: KeyboardEvent): void {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    const key = event.key.toLowerCase();
    const action = (event.shiftKey ? SHIFTED_SHORTCUTS : SHORTCUTS)[key];

    if (!action) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    action();
}

function onFocusOut(event: FocusEvent): void {
    listFocused.value =
        list.value?.contains(event.relatedTarget as Node) ?? false;
}

watch(selected, async (offset) => {
    await nextTick();
    list.value
        ?.querySelectorAll('[data-sort-row]')
        [offset]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});

watch(
    () => player.state.queueOpen,
    async (open) => {
        if (open) {
            await nextTick();
            list.value?.focus({ preventScroll: true });
        }
    },
);
</script>

<template>
    <aside
        class="material-glass queue min-h-0 overflow-hidden rounded-none border-y-0 border-r-0 transition-[width] duration-[240ms] ease-out-quint"
        :class="player.state.queueOpen ? 'w-[340px]' : 'w-0 border-l-0'"
        :aria-label="$t('Queue')"
        :aria-hidden="!player.state.queueOpen"
    >
        <div class="h-full w-[340px] overflow-y-auto px-3 pt-4 pb-32">
            <h2 class="px-2 pb-2.5 text-[13.5px] font-bold">
                {{ $t('Now playing') }}
            </h2>
            <button
                v-if="player.current.value"
                type="button"
                class="group mb-4 grid w-full grid-cols-[64px_1fr] gap-3 rounded-[10px] bg-raised p-2 text-left"
                :aria-label="
                    $t(player.state.playing ? 'Pause :title' : 'Play :title', {
                        title: player.current.value.title,
                    })
                "
                @click="player.toggle()"
            >
                <PlayableArtwork
                    :src="player.current.value.thumbnailUrl"
                    :playing="player.state.playing"
                    class="size-16 rounded-md"
                />
                <span class="min-w-0 self-center">
                    <span class="block truncate font-bold">
                        {{ player.current.value.title }}
                    </span>
                    <span class="block truncate text-[13px] text-dim">
                        {{ player.current.value.artists }}
                    </span>
                </span>
            </button>
            <p v-else class="mb-4 px-2 text-sm text-faint">
                {{ $t('Nothing is playing.') }}
            </p>

            <h2
                class="flex origin-left items-baseline gap-2 px-2 pb-2.5 text-[13.5px] font-bold"
                :data-queue-target="
                    player.state.queueOpen ? 'panel' : undefined
                "
            >
                {{ $t('Up next') }}
                <span
                    v-if="player.state.source"
                    class="truncate text-[12.5px] font-medium text-faint"
                >
                    {{
                        $t('from :source', {
                            source: player.state.source.title,
                        })
                    }}
                </span>
            </h2>
            <p
                v-if="visible.length > 1"
                class="flex items-center gap-3 px-2 pb-2 text-faint transition-opacity duration-200"
                :class="listFocused ? 'opacity-100' : 'opacity-60'"
            >
                <span
                    v-for="hint in HINTS"
                    :key="hint.label"
                    class="inline-flex items-center gap-1 whitespace-nowrap"
                    :title="$t(hint.label)"
                >
                    <kbd v-for="key in hint.keys" :key="key" class="keycap">{{
                        key
                    }}</kbd>
                    <component
                        :is="hint.icon"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    <span class="sr-only">{{ $t(hint.label) }}</span>
                </span>
            </p>
            <div
                ref="list"
                class="up-next rounded-lg outline-none"
                tabindex="0"
                :aria-label="$t('Up next')"
                @keydown="onKeydown"
                @focusin="listFocused = true"
                @focusout="onFocusOut"
            >
                <TransitionGroup
                    tag="div"
                    name="list"
                    class="relative"
                    :class="{ 'is-sorting': isSorting() }"
                >
                    <TrackMiniRow
                        v-for="(track, offset) in visible"
                        :key="track.key"
                        data-sort-row
                        :track="track"
                        :number="offset + 1"
                        sortable
                        :selected="listFocused && offset === selected"
                        :grabbed="isGrabbed(offset)"
                        :style="styleFor(offset)"
                        @select="(event) => select(event, offset)"
                        @remove="remove(offset)"
                        @grab="(event) => grab(event, offset)"
                    />
                </TransitionGroup>
            </div>
            <p
                v-if="player.current.value && player.upNext.value.length === 0"
                class="px-2 text-sm text-faint"
            >
                {{ $t('This is the last track.') }}
            </p>
            <button
                type="button"
                class="mt-6 px-2 text-xs text-faint transition-colors hover:text-dim"
                :aria-expanded="debugOpen"
                @click="debugOpen = !debugOpen"
            >
                {{ $t('Debug') }}
            </button>
            <PlayerDebugPanel v-if="debugOpen" class="mt-2" />
        </div>
    </aside>
</template>
