<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { ref } from 'vue';
import UserLocaleController from '@/actions/App/Http/Controllers/UserLocaleController';
import Heading from '@/components/Heading.vue';

/** Each language is named in itself, so it stays readable whichever is active. */
const LANGUAGES: { locale: App.Enums.Locale; label: string }[] = [
    { locale: 'fr_BE', label: 'Français' },
    { locale: 'en_US', label: 'English' },
];

const page = usePage();
const processing = ref(false);

function choose(locale: App.Enums.Locale): void {
    if (locale === page.props.locale || processing.value) {
        return;
    }

    router.visit(UserLocaleController.update(), {
        data: { locale },
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: async () => {
            await loadLanguageAsync(locale);
            document.documentElement.lang = locale.replace('_', '-');
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Language')"
            :description="$t('Choose the language of the interface')"
        />

        <div
            role="radiogroup"
            :aria-label="$t('Language')"
            class="inline-flex gap-1 rounded-lg border border-border bg-card p-1"
        >
            <button
                v-for="language in LANGUAGES"
                :key="language.locale"
                type="button"
                role="radio"
                :lang="language.locale.replace('_', '-')"
                :aria-checked="page.props.locale === language.locale"
                :disabled="processing"
                class="rounded-md px-3.5 py-1.5 text-sm transition-colors not-aria-checked:text-muted-foreground not-aria-checked:hover:text-foreground disabled:opacity-60 aria-checked:bg-primary aria-checked:text-primary-foreground"
                @click="choose(language.locale)"
            >
                {{ language.label }}
            </button>
        </div>
    </div>
</template>
