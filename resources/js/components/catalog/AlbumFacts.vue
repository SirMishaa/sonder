<script setup lang="ts">
import { computed } from 'vue';
import ClampedDescription from '@/components/catalog/ClampedDescription.vue';
import GenreTags from '@/components/music/GenreTags.vue';
import { formatCompactNumber, formatListeningTime } from '@/lib/i18n';

/**
 * The album page's facts under its actions: running time and track count,
 * total plays, how much of it is in the library, then its genres and the
 * description YouTube Music ships with it, clamped.
 */
type Props = {
    page: App.Data.AlbumPageData;
};

const props = defineProps<Props>();

const trackCount = computed(() => props.page.tracks.length);
const duration = computed(() =>
    props.page.durationSeconds
        ? formatListeningTime(props.page.durationSeconds)
        : null,
);
</script>

<template>
    <div class="album-facts">
        <dl class="facts">
            <div>
                <dt>{{ $t('Duration') }}</dt>
                <dd>
                    <template v-if="duration">{{ duration }} · </template
                    >{{ $tChoice(':count track|:count tracks', trackCount) }}
                </dd>
            </div>
            <div v-if="page.totalPlays > 0">
                <dt>{{ $t('Plays') }}</dt>
                <dd class="tabular-nums">
                    {{ formatCompactNumber(page.totalPlays, 3) }}
                </dd>
            </div>
            <div>
                <dt>{{ $t('In your library') }}</dt>
                <dd class="tabular-nums">
                    {{
                        $t(':count of :total', {
                            count: String(page.inLibraryCount),
                            total: String(trackCount),
                        })
                    }}
                </dd>
            </div>
        </dl>

        <div v-if="page.genres.length" class="mt-4 flex flex-wrap gap-1.5">
            <GenreTags :genres="page.genres" />
        </div>

        <ClampedDescription
            v-if="page.description"
            :key="page.album.id"
            class="mt-[18px]"
            :text="page.description"
            :source="$t('YouTube Music')"
        />
    </div>
</template>

<style scoped>
.facts {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: 9px 14px;
    margin-top: 26px;
    padding-top: 18px;
    border-top: 1px solid oklch(0.285 0.012 60 / 0.55);
    font-size: 13px;
    font-weight: 500;
}

.facts div {
    display: contents;
}

.facts dt {
    color: var(--color-faint);
}
</style>
