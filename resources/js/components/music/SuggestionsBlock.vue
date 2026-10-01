<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Plus, Sparkles, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import PlayableArtwork from '@/components/music/PlayableArtwork.vue';
import { usePlayer } from '@/composables/usePlayer';
import { useShortcuts } from '@/composables/useShortcuts';
import { useToast } from '@/composables/useToast';
import { genreTagsFor, nearArtistFrom } from '@/lib/fixtures';

/**
 * "Would fit this playlist". Fixture: the pool is sampled from the user's own
 * library, and the "near" artist and genre tags come from `@/lib/fixtures`.
 * Adding or skipping only changes this view; nothing is written anywhere.
 * Playing a suggestion starts it right away, the queue resuming after it.
 */
type Props = {
    pool: App.Data.SampledTrackData[];
    playlistTracks: App.Data.TrackData[];
    playlistTitle: string;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    add: [track: App.Data.TrackData];
}>();

const { toast } = useToast();
const player = usePlayer();

type Suggestion = {
    key: string;
    track: App.Data.TrackData;
    near: string | null;
    tags: string[];
    leaving: boolean;
};

const suggestions = ref<Suggestion[]>([]);
const selected = ref(-1);

watch(
    () => props.pool,
    (pool) => {
        suggestions.value = pool.map(({ track }) => ({
            key: track.videoId ?? track.title,
            track,
            near: nearArtistFrom(props.playlistTracks, track.title),
            tags: genreTagsFor(track.artists),
            leaving: false,
        }));
        selected.value = -1;
    },
    { immediate: true },
);

const remaining = computed(
    () => suggestions.value.filter((suggestion) => !suggestion.leaving).length,
);

const LEAVE_MS = 320;

function resolve(index: number, action: 'add' | 'skip'): void {
    const suggestion = suggestions.value[index];

    if (!suggestion || suggestion.leaving) {
        return;
    }

    suggestion.leaving = true;

    setTimeout(() => {
        suggestions.value = suggestions.value.filter(
            (item) => item !== suggestion,
        );
        selected.value = Math.min(selected.value, suggestions.value.length - 1);
    }, LEAVE_MS);

    if (action === 'add') {
        emit('add', suggestion.track);
        toast(
            trans('Added :title to :playlist (preview only, not saved)', {
                playlist: props.playlistTitle,
            }),
            suggestion.track.title,
        );
    } else {
        toast(trans('Skipped :title'), suggestion.track.title);
    }
}

function play(suggestion: Suggestion): void {
    player.playNext(
        suggestion.track,
        {
            playlistId: null,
            title: trans('Suggestions for :playlist', {
                playlist: props.playlistTitle,
            }),
        },
        'suggestion',
    );
}

function move(delta: number): void {
    if (!suggestions.value.length) {
        return;
    }

    selected.value = Math.max(
        0,
        Math.min(suggestions.value.length - 1, selected.value + delta),
    );
}

useShortcuts({
    j: () => move(1),
    k: () => move(-1),
    a: () => resolve(selected.value, 'add'),
    x: () => resolve(selected.value, 'skip'),
});
</script>

<template>
    <section
        class="suggest overflow-hidden rounded-xl border border-line"
        :aria-label="$t('Suggestions')"
    >
        <header
            class="flex items-center gap-2.5 border-b border-line px-3.5 py-2.5"
        >
            <Sparkles class="size-[18px] text-amber" />
            <h2 class="font-bold">{{ $t('Would fit this playlist') }}</h2>
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
            v-for="(suggestion, index) in suggestions"
            :key="suggestion.key"
            class="row grid grid-cols-[36px_minmax(0,1.2fr)_minmax(0,1.6fr)_auto] items-center gap-3.5 px-3.5 py-2"
            :class="{
                'is-selected': index === selected,
                'is-leaving': suggestion.leaving,
            }"
        >
            <button
                type="button"
                class="group size-9 rounded disabled:opacity-60"
                :aria-label="
                    $t('Play :title', { title: suggestion.track.title })
                "
                :disabled="
                    !suggestion.track.isAvailable || !suggestion.track.videoId
                "
                @click="play(suggestion)"
            >
                <PlayableArtwork
                    :src="suggestion.track.thumbnailUrl"
                    class="size-9 rounded"
                />
            </button>
            <div class="min-w-0">
                <p class="truncate font-bold">{{ suggestion.track.title }}</p>
                <p class="truncate text-[13px] text-dim">
                    {{ suggestion.track.artists }}
                </p>
            </div>
            <div
                class="flex flex-wrap items-center gap-1.5 text-[12.5px] font-medium text-faint"
            >
                <template v-if="suggestion.near">
                    {{ $t('near') }}
                    <em class="font-semibold text-amber not-italic">{{
                        suggestion.near
                    }}</em>
                </template>
                <span
                    v-for="tag in suggestion.tags"
                    :key="tag"
                    class="rounded-full border border-line px-2 py-px text-xs font-medium text-dim"
                >
                    {{ tag }}
                </span>
            </div>
            <div class="flex gap-1.5">
                <button
                    type="button"
                    class="action add"
                    :aria-label="`Add ${suggestion.track.title}`"
                    @click="resolve(index, 'add')"
                >
                    <Plus class="size-4" />
                </button>
                <button
                    type="button"
                    class="action"
                    :aria-label="`Skip ${suggestion.track.title}`"
                    @click="resolve(index, 'skip')"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>

        <p v-if="!suggestions.length" class="px-3.5 py-4 text-sm text-faint">
            {{
                $t('All caught up. New suggestions arrive with the next sync.')
            }}
        </p>
        <p
            class="border-t border-line/55 px-3.5 py-1.5 text-xs text-faint italic"
        >
            {{
                $t(
                    'Preview: tracks sampled from your library; genre tags will come from Last.fm.',
                )
            }}
        </p>
    </section>
</template>

<style scoped>
.suggest {
    background: oklch(0.19 0.013 60 / 0.72);
    backdrop-filter: blur(10px);
}

.row {
    transition: background 140ms;
}

.row + .row {
    border-top: 1px solid oklch(0.285 0.012 60 / 0.55);
}

.row.is-selected {
    background: var(--color-raised);
    box-shadow: inset 0 0 0 1px oklch(0.8 0.15 68 / 0.45);
}

.row.is-leaving {
    animation: slide-out 320ms var(--ease-in-expo) forwards;
}

.action {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid var(--color-line);
    color: var(--color-dim);
    transition: all 140ms;
}

.action:hover {
    background: var(--color-raised);
    color: var(--color-paper);
}

.action:disabled {
    opacity: 0.4;
    pointer-events: none;
}

.action.add:hover {
    background: var(--accent-grad-d);
    border-color: transparent;
    color: var(--color-amber-ink);
}
</style>
