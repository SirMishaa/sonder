<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CookieIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

const page = usePage();

const connectionUrl = YouTubeMusicConnectionController.create.url();

const isVisible = computed(
    () =>
        page.props.youtubeMusicCookieExpired &&
        page.url.split('?')[0] !== connectionUrl,
);
</script>

<template>
    <div v-if="isVisible" class="px-8 pt-6">
        <Alert variant="destructive">
            <CookieIcon class="size-4" />
            <AlertTitle>{{
                $t('Your YouTube Music cookie stopped working')
            }}</AlertTitle>
            <AlertDescription class="flex flex-wrap items-center gap-3">
                <span>
                    {{
                        $t(
                            "Your library can't be synced until you paste a fresh one.",
                        )
                    }}
                </span>
                <Button as-child variant="outline" size="sm">
                    <Link :href="connectionUrl">{{
                        $t('Update the cookie')
                    }}</Link>
                </Button>
            </AlertDescription>
        </Alert>
    </div>
</template>
