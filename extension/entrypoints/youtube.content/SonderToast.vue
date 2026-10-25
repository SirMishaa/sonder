<script setup lang="ts">
defineProps<{
    message: string | null;
}>();
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out-quint motion-reduce:transition-none"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-out-quint motion-reduce:transition-none"
        leave-to-class="opacity-0"
    >
        <p
            v-if="message"
            role="status"
            class="fixed bottom-6 left-1/2 z-[2300] -translate-x-1/2 rounded-[9px] bg-[oklch(0.21_0.014_60/0.9)] px-3.5 py-2 font-sans text-[13.5px] font-semibold text-paper shadow-[0_18px_30px_-20px_oklch(0_0_0/0.8),inset_0_1px_0_oklch(1_0_0/0.08)] backdrop-blur-md"
        >
            {{ message }}
        </p>
    </Transition>
</template>
