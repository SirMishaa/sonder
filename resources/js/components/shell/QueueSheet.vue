<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import QueueContents from '@/components/shell/QueueContents.vue';
import { useMobileSheets } from '@/composables/useMobileSheets';
import { useSheetDrag } from '@/composables/useSheetDrag';

/**
 * Up next on a phone: the queue panel's contents in a full-screen sheet
 * over now playing. Only its header pulls it down, the list scrolls.
 */
const { sheets } = useMobileSheets();

const close = (): void => {
    sheets.queue = false;
};

const { offset, dragging, grab } = useSheetDrag(close);
</script>

<template>
    <Transition name="sheet">
        <section
            v-if="sheets.queue"
            class="sheet fixed inset-0 z-50 flex flex-col bg-ink"
            :class="{ 'is-dragging': dragging }"
            :style="{ '--drag': `${offset}px` }"
            role="dialog"
            :aria-label="$t('Queue')"
        >
            <header
                class="flex touch-none items-center gap-2 border-b border-line px-3 py-2 select-none"
                @pointerdown="grab"
            >
                <button
                    type="button"
                    class="grid size-11 place-items-center rounded-full text-dim active:bg-raised"
                    :aria-label="$t('Close')"
                    @click="close"
                >
                    <ChevronDown class="size-6" />
                </button>
                <h2 class="text-[15px] font-bold">{{ $t('Queue') }}</h2>
            </header>
            <QueueContents
                :active="sheets.queue"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pt-4 pb-8"
            />
        </section>
    </Transition>
</template>
