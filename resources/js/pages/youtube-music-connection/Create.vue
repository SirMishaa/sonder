<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ExternalLink,
    Music4,
    TriangleAlert,
} from 'lucide-vue-next';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/i18n';

defineOptions({ layout: AppLayout });

type Props = {
    account: {
        account_name: string;
        last_verified_at: string;
        cookie_expired_at: string | null;
    } | null;
};

defineProps<Props>();

const steps = [
    'Open a private (incognito) window and sign in to music.youtube.com there.',
    'Open the developer tools and select the Network tab.',
    'Reload the page, then click any request sent to music.youtube.com.',
    'Under Request Headers, copy the entire value of the "cookie" header.',
    'Close the private window without signing out.',
];
</script>

<template>
    <Head :title="$t('Connect YouTube Music')" />

    <div class="mx-auto w-full max-w-3xl space-y-8 p-4">
        <Heading
            :title="$t('Connect YouTube Music')"
            :description="
                $t(
                    'Sonder reads your library through YouTube Music\'s private API. It needs your browser session to do that.',
                )
            "
        />

        <div
            v-if="account"
            class="flex items-center gap-3 rounded-xl border p-4"
            :class="
                account.cookie_expired_at
                    ? 'border-destructive/40 bg-destructive/5'
                    : 'border-emerald-600/30 bg-emerald-500/5'
            "
        >
            <TriangleAlert
                v-if="account.cookie_expired_at"
                class="size-5 shrink-0 text-destructive"
            />
            <CheckCircle2 v-else class="size-5 shrink-0 text-emerald-600" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-medium">
                    <template v-if="account.cookie_expired_at">
                        {{
                            $t('The cookie for :account stopped working', {
                                account: account.account_name,
                            })
                        }}
                    </template>
                    <template v-else>
                        {{
                            $t('Connected as :account', {
                                account: account.account_name,
                            })
                        }}
                    </template>
                </p>
                <p class="text-muted-foreground">
                    <template v-if="account.cookie_expired_at">
                        {{
                            $t(
                                'Refused since :date. Paste a fresh cookie below.',
                                {
                                    date: formatDateTime(
                                        account.cookie_expired_at,
                                    ),
                                },
                            )
                        }}
                    </template>
                    <template v-else>
                        {{
                            $t('Last verified :date', {
                                date: formatDateTime(account.last_verified_at),
                            })
                        }}
                    </template>
                </p>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link :href="PlaylistController.index()">
                    <Music4 />
                    {{ $t('Playlists') }}
                </Link>
            </Button>
        </div>

        <div class="rounded-xl border p-6">
            <h2 class="text-sm font-medium">
                {{ $t('Where to find the cookie') }}
            </h2>
            <ol
                class="mt-3 list-inside list-decimal space-y-1.5 text-sm text-muted-foreground"
            >
                <li v-for="step in steps" :key="step">{{ step }}</li>
            </ol>
            <p class="mt-3 text-sm text-muted-foreground">
                {{
                    $t(
                        'Why a private window: a browser that keeps using the same session keeps rotating its cookies, and Google soon rejects the copy you pasted. A session nobody uses anymore is left alone, so its cookie lasts much longer.',
                    )
                }}
            </p>
            <a
                href="https://music.youtube.com"
                target="_blank"
                rel="noreferrer noopener"
                class="mt-4 inline-flex items-center gap-1.5 text-sm underline underline-offset-4"
            >
                {{ $t('Open YouTube Music') }}
                <ExternalLink class="size-3.5" />
            </a>
        </div>

        <Form
            v-bind="YouTubeMusicConnectionController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="cookie">{{ $t('Cookie header') }}</Label>
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
                    {{
                        $t(
                            'Stored encrypted, used only to read your library. Sonder checks it once a day and warns you as soon as YouTube Music stops accepting it.',
                        )
                    }}
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
                        :href="YouTubeMusicConnectionController.destroy.url()"
                        method="delete"
                        as="button"
                    >
                        {{ $t('Disconnect') }}
                    </Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
