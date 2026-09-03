<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, ExternalLink, Music4 } from 'lucide-vue-next';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    account: { account_name: string; last_verified_at: string } | null;
};

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'YouTube Music', href: YouTubeMusicConnectionController.create() },
];

const steps = [
    'Open music.youtube.com in your browser and make sure you are signed in.',
    'Open the developer tools and select the Network tab.',
    'Reload the page, then click any request sent to music.youtube.com.',
    'Under Request Headers, copy the entire value of the "cookie" header.',
];
</script>

<template>
    <Head title="Connect YouTube Music" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-8 p-4">
            <Heading
                title="Connect YouTube Music"
                description="Sonder reads your library through YouTube Music's private API. It needs your browser session to do that."
            />

            <div
                v-if="account"
                class="flex items-center gap-3 rounded-xl border border-emerald-600/30 bg-emerald-500/5 p-4"
            >
                <CheckCircle2 class="size-5 shrink-0 text-emerald-600" />
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-medium">
                        Connected as {{ account.account_name }}
                    </p>
                    <p class="text-muted-foreground">
                        Last verified
                        {{
                            new Date(account.last_verified_at).toLocaleString()
                        }}
                    </p>
                </div>
                <Button as-child variant="outline" size="sm">
                    <Link :href="PlaylistController.index()">
                        <Music4 />
                        Playlists
                    </Link>
                </Button>
            </div>

            <div class="rounded-xl border p-6">
                <h2 class="text-sm font-medium">Where to find the cookie</h2>
                <ol
                    class="mt-3 list-inside list-decimal space-y-1.5 text-sm text-muted-foreground"
                >
                    <li v-for="step in steps" :key="step">{{ step }}</li>
                </ol>
                <a
                    href="https://music.youtube.com"
                    target="_blank"
                    rel="noreferrer noopener"
                    class="mt-4 inline-flex items-center gap-1.5 text-sm underline underline-offset-4"
                >
                    Open YouTube Music
                    <ExternalLink class="size-3.5" />
                </a>
            </div>

            <Form
                v-bind="YouTubeMusicConnectionController.store.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="cookie">Cookie header</Label>
                    <textarea
                        id="cookie"
                        name="cookie"
                        required
                        rows="5"
                        spellcheck="false"
                        autocomplete="off"
                        placeholder="VISITOR_INFO1_LIVE=...; __Secure-3PAPISID=...; SAPISID=...; SID=..."
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs transition outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <InputError :message="errors.cookie" />
                    <p class="text-xs text-muted-foreground">
                        Stored encrypted, used only to read your library. It
                        typically expires after a few weeks, at which point you
                        will need to paste a fresh one.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Button :disabled="processing" data-test="connect-button">
                        {{ account ? 'Update connection' : 'Connect' }}
                    </Button>

                    <Button
                        v-if="account"
                        as-child
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                    >
                        <Link
                            :href="
                                YouTubeMusicConnectionController.destroy.url()
                            "
                            method="delete"
                            as="button"
                        >
                            Disconnect
                        </Link>
                    </Button>
                </div>
            </Form>
        </div>
    </AppLayout>
</template>
