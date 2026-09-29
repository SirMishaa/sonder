<script setup lang="ts">
import { useToast } from '@/composables/useToast';

const { toasts } = useToast();
</script>

<template>
    <div
        class="pointer-events-none absolute inset-x-0 bottom-28 z-40 flex justify-center"
        aria-live="polite"
    >
        <TransitionGroup name="toast">
            <p
                v-for="toast in toasts"
                :key="toast.id"
                class="rounded-[10px] border border-line bg-raised px-3.5 py-2 text-[13px] shadow-lg"
            >
                {{ toast.text
                }}<b v-if="toast.emphasis" class="font-semibold text-amber">{{
                    toast.emphasis
                }}</b
                >{{ toast.after }}
            </p>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition:
        opacity 240ms,
        transform 240ms var(--ease-out-quint);
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(6px);
}

.toast-leave-active {
    position: absolute;
}
</style>
