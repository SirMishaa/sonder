<script setup lang="ts">
import { Plus, RotateCcw, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CoverArt from '@/components/landing/CoverArt.vue';
import { suggestions as showcase } from '@/lib/landing';
import type { ShowcaseSuggestion } from '@/lib/landing';

type Row = ShowcaseSuggestion & { leaving: boolean };

const LEAVE_MS = 260;

const rows = ref<Row[]>([]);
const added = ref<ShowcaseSuggestion[]>([]);
const selected = ref(0);

function reset(): void {
    rows.value = showcase.map((suggestion) => ({
        ...suggestion,
        leaving: false,
    }));
    added.value = [];
    selected.value = 0;
}

reset();

const remaining = computed(
    () => rows.value.filter((row) => !row.leaving).length,
);

/**
 * The same gesture as the real suggestions block: the row slides out, and an
 * added track lands at the bottom of the playlist with an amber flash.
 */
function resolve(index: number, add: boolean): void {
    const row = rows.value[index];

    if (!row || row.leaving) {
        return;
    }

    row.leaving = true;

    window.setTimeout(() => {
        rows.value = rows.value.filter((candidate) => candidate !== row);
        selected.value = Math.min(
            selected.value,
            Math.max(0, rows.value.length - 1),
        );

        if (add) {
            added.value = [...added.value, row];
        }
    }, LEAVE_MS);
}

function onKeydown(event: KeyboardEvent): void {
    const actions: Record<string, () => void> = {
        j: () =>
            (selected.value = Math.min(
                selected.value + 1,
                rows.value.length - 1,
            )),
        k: () => (selected.value = Math.max(selected.value - 1, 0)),
        a: () => resolve(selected.value, true),
        x: () => resolve(selected.value, false),
    };

    const action = actions[event.key.toLowerCase()];

    if (action && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault();
        action();
    }
}
</script>

<template>
    <div
        class="fragment rounded-xl border border-line"
        tabindex="0"
        role="group"
        :aria-label="
            $t('Try the suggestions: J and K to move, A to add, X to skip')
        "
        @keydown="onKeydown"
    >
        <header
            class="flex items-center gap-2.5 border-b border-line px-3.5 py-2.5"
        >
            <Sparkles class="size-[18px] text-amber" />
            <p class="font-bold">{{ $t('Would fit this playlist') }}</p>
            <span class="text-[12.5px] font-medium text-faint">{{
                $tChoice(':count never played|:count never played', remaining)
            }}</span>
            <span
                class="ml-auto hidden items-center gap-1.5 text-xs font-medium text-faint md:flex"
            >
                <kbd class="keycap">J</kbd><kbd class="keycap">K</kbd>
                {{ $t('move') }} <kbd class="keycap">A</kbd> {{ $t('add') }}
                <kbd class="keycap">X</kbd> {{ $t('skip') }}
            </span>
        </header>

        <div
            v-for="(row, index) in rows"
            :key="row.title"
            class="row grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3.5 px-3.5 py-2 sm:grid-cols-[36px_minmax(0,1.1fr)_minmax(0,1.4fr)_auto]"
            :class="{
                'is-selected': index === selected,
                'is-leaving': row.leaving,
            }"
            @click="selected = index"
        >
            <CoverArt :hue="row.hue" class="size-9 overflow-hidden rounded" />
            <div class="min-w-0">
                <p class="truncate font-bold">{{ row.title }}</p>
                <p class="truncate text-[13px] text-dim">{{ row.artists }}</p>
            </div>
            <div
                class="hidden flex-wrap items-center gap-1.5 text-[12.5px] font-medium text-faint sm:flex"
            >
                {{ $t('near') }}
                <em class="font-semibold text-amber not-italic">{{
                    row.near
                }}</em>
                <span
                    v-for="genre in row.genres"
                    :key="genre"
                    class="rounded-full border border-line px-2 text-dim"
                    >{{ genre }}</span
                >
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="icon-button"
                    tabindex="-1"
                    :aria-label="$t('Add :title', { title: row.title })"
                    @click.stop="resolve(index, true)"
                >
                    <Plus class="size-4" />
                </button>
                <button
                    type="button"
                    class="icon-button"
                    tabindex="-1"
                    :aria-label="$t('Skip :title', { title: row.title })"
                    @click.stop="resolve(index, false)"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>

        <div
            v-if="rows.length === 0"
            class="flex items-center justify-between gap-3 px-3.5 py-3 text-[13px] text-dim"
        >
            {{
                $t('All caught up. New suggestions arrive with the next sync.')
            }}
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-md px-2 py-1 font-semibold text-paper hover:bg-raised"
                @click="reset"
            >
                <RotateCcw class="size-3.5" />
                {{ $t('Start over') }}
            </button>
        </div>

        <ol
            v-if="added.length"
            class="border-t border-line px-3.5 py-2"
            aria-live="polite"
        >
            <li
                v-for="track in added"
                :key="track.title"
                class="added flex items-center gap-2.5 rounded-md px-1.5 py-1 text-[13px]"
            >
                <Plus class="size-3.5 text-amber" />
                <span class="min-w-0 truncate">
                    <b class="font-semibold">{{ track.title }}</b>
                    <span class="text-dim">
                        ·
                        {{
                            $t('added to :playlist', { playlist: 'Late focus' })
                        }}</span
                    >
                </span>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.fragment {
    background: oklch(0.19 0.013 60 / 0.72);
    backdrop-filter: blur(4px);
    outline-offset: 4px;
}

.row {
    cursor: default;
    transition: background 140ms;
}

.row:hover {
    background: var(--color-hover);
}

.row.is-selected {
    background: var(--color-raised);
    box-shadow: inset 0 0 0 1px oklch(0.8 0.15 68 / 0.75);
}

.row.is-leaving {
    animation: slide-out 260ms var(--ease-out-quint) forwards;
}

.added {
    animation: flash-amber 900ms var(--ease-out-quint);
}

.icon-button {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 999px;
    color: var(--color-dim);
    transition:
        color 140ms,
        background 140ms;
}

.icon-button:hover {
    color: var(--color-paper);
    background: var(--color-hover);
}
</style>
