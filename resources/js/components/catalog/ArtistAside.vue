<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import { useRelativeTime } from '@/composables/useRelativeTime';
import { formatNumber } from '@/lib/i18n';

/**
 * The artist page's side column: what the listener already has and has
 * played from the artist ("Your listening"), then the artist's biography
 * clamped to four lines with its source.
 */
type Props = {
    artistName: string;
    inLibrary: App.Data.ArtistInLibraryData;
    description: string | null;
};

const props = defineProps<Props>();

const firstListen = useRelativeTime(() => props.inLibrary.firstListenedAt);
const lastListen = useRelativeTime(() => props.inLibrary.lastListenedAt);

const isEmpty = computed(
    () => props.inLibrary.trackCount === 0 && props.inLibrary.listenCount === 0,
);

const stats = computed(() => [
    { count: props.inLibrary.trackCount, label: 'track|tracks' },
    { count: props.inLibrary.albumCount, label: 'album|albums' },
    { count: props.inLibrary.listenCount, label: 'listen|listens' },
]);

const expanded = ref(false);

/** Line breaks only once expanded; clamped, the text runs on. */
const shownDescription = computed(() =>
    expanded.value
        ? (props.description ?? '').trim()
        : (props.description ?? '').replace(/\s+/g, ' ').trim(),
);
const overflowing = ref(false);
const text = useTemplateRef<HTMLElement>('text');

/** Whether the clamp hides anything, so "Read more" only shows when useful. */
useResizeObserver(text, () => {
    const element = text.value;

    if (element && !expanded.value) {
        overflowing.value = element.scrollHeight > element.clientHeight + 1;
    }
});
</script>

<template>
    <aside class="aside">
        <section class="mine" :aria-label="$t('Your listening')">
            <h2 class="aside-title">{{ $t('Your listening') }}</h2>

            <p v-if="isEmpty" class="text-[13px] text-dim">
                {{
                    $t('Nothing by :artist in your library or history yet.', {
                        artist: artistName,
                    })
                }}
            </p>

            <template v-else>
                <dl class="grid grid-cols-3 gap-2">
                    <div
                        v-for="stat in stats"
                        :key="stat.label"
                        class="flex flex-col-reverse"
                    >
                        <dt class="text-[12.5px] font-medium text-faint">
                            {{ $tChoice(stat.label, stat.count) }}
                        </dt>
                        <dd
                            class="text-xl font-extrabold tracking-[-0.02em] tabular-nums"
                        >
                            {{ formatNumber(stat.count) }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="inLibrary.listenCount > 0"
                    class="mt-2.5 text-[12.5px] text-dim"
                >
                    {{
                        $t(':count min listened', {
                            count: formatNumber(inLibrary.listenedMinutes),
                        })
                    }}
                    <template v-if="firstListen">
                        · {{ $t('first listen :ago', { ago: firstListen }) }}
                    </template>
                    <template v-if="lastListen">
                        · {{ $t('last listen :ago', { ago: lastListen }) }}
                    </template>
                </p>

                <template v-if="inLibrary.topTracks.length">
                    <h3 class="mt-3.5 mb-1 text-xs font-bold text-faint">
                        {{ $t('Your most played') }}
                    </h3>
                    <ol>
                        <li
                            v-for="(track, index) in inLibrary.topTracks"
                            :key="track.title"
                            class="grid grid-cols-[16px_minmax(0,1fr)_auto] gap-2.5 py-[5px] text-[13px]"
                        >
                            <span class="text-right text-faint tabular-nums">{{
                                index + 1
                            }}</span>
                            <span class="truncate font-semibold">{{
                                track.title
                            }}</span>
                            <span
                                class="text-[12.5px] font-medium text-faint tabular-nums"
                                >{{
                                    $tChoice(
                                        ':count listen|:count listens',
                                        track.listens,
                                    )
                                }}</span
                            >
                        </li>
                    </ol>
                </template>
            </template>
        </section>

        <section v-if="description" class="about">
            <h2 class="aside-title">{{ $t('About') }}</h2>
            <p
                id="artist-description"
                ref="text"
                class="desc"
                :class="{ open: expanded }"
                v-text="shownDescription"
            />
            <div class="mt-1.5 flex items-baseline gap-2.5">
                <button
                    v-if="overflowing"
                    type="button"
                    class="text-[12.5px] font-semibold text-paper transition-colors duration-150 hover:text-amber"
                    aria-controls="artist-description"
                    :aria-expanded="expanded"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? $t('Show less') : $t('Read more') }}
                </button>
                <span class="text-xs text-faint">{{
                    $t('YouTube Music')
                }}</span>
            </div>
        </section>
    </aside>
</template>

<style scoped>
.aside {
    display: grid;
    align-content: start;
    gap: 28px;
}

.aside-title {
    margin-bottom: 10px;
    font-size: 14px;
    font-weight: 700;
}

.mine {
    padding: 16px;
    border: 1px solid var(--color-line);
    border-radius: 12px;
    background: oklch(0.19 0.013 60 / 0.6);
}

.about {
    padding-top: 24px;
    border-top: 1px solid oklch(0.285 0.012 60 / 0.55);
}

.desc {
    display: -webkit-box;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    color: var(--color-dim);
    font-size: 13px;
    line-height: 1.55;
}

.desc.open {
    display: block;
    white-space: pre-line;
}
</style>
