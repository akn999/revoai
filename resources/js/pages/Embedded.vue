<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { embedded } from '@salla.sa/embedded-sdk';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MediaTab from '@/components/revo/MediaTab.vue';
import OverviewTab from '@/components/revo/OverviewTab.vue';
import ProductsTab from '@/components/revo/ProductsTab.vue';
import SettingsTab from '@/components/revo/SettingsTab.vue';
import { get, startSession, type Overview, type SessionState } from '@/lib/revo/api';
import { direction, locale, setLocale, t } from '@/lib/revo/i18n';

const TABS = ['overview', 'products', 'media', 'settings'] as const;
type Tab = (typeof TABS)[number];

const state = ref<SessionState | 'loading'>('loading');
const tab = ref<Tab>('overview');
const overview = ref<Overview | null>(null);
const languages = ref<string[]>(['ar', 'en']);

const lowBalance = computed(() => overview.value?.credits.low ?? false);

async function refreshOverview() {
    overview.value = (await get<{ data: Overview }>('/overview')).data;
}

function pick(next: Tab) {
    tab.value = next;
    embedded.page.setTitle(`${t('app.title')} · ${t(`tab.${next}`)}`);
}

onMounted(async () => {
    try {
        const { layout } = await embedded.init({ debug: false });
        document.documentElement.classList.toggle('dark', layout.theme === 'dark');
        setLocale(layout.locale);
        document.documentElement.lang = locale.value;
        document.documentElement.dir = direction.value;
        embedded.page.setTitle(t('app.title'));
        state.value = await startSession();

        if (state.value === 'ready' || state.value === 'awaiting_authorization') {
            await refreshOverview();
            const settings = await get<{ data: { general: { enabled_languages: string[] } } }>('/settings');
            languages.value = settings.data.general.enabled_languages;
        }
    } catch (error) {
        console.error('Revo AI failed to start', error);
        state.value = 'failed';
    } finally {
        embedded.ready();
    }
});

onBeforeUnmount(() => embedded.destroy());
</script>

<template>
    <Head :title="t('app.title')" />
    <div :dir="direction" class="min-h-screen bg-white p-4 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <p v-if="state === 'loading'" class="animate-pulse">{{ t('app.loading') }}</p>
        <p v-else-if="state === 'not_installed'" role="alert">{{ t('app.notInstalled') }}</p>
        <p v-else-if="state === 'failed'" role="alert">{{ t('app.sessionFailed') }}</p>

        <template v-else>
            <p v-if="state === 'awaiting_authorization'" class="mb-3 rounded border p-3">{{ t('app.awaiting') }}</p>

            <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <nav class="flex gap-1" role="tablist">
                    <button v-for="name in TABS" :key="name" role="tab" :aria-selected="tab === name" class="rounded px-4 py-2" :class="tab === name ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900' : 'border'" @click="pick(name)">{{ t(`tab.${name}`) }}</button>
                </nav>
                <span v-if="overview" class="rounded border px-3 py-1 text-sm">{{ t('credits.balance') }}: <strong>{{ overview.credits.available }}</strong></span>
            </header>

            <p v-if="lowBalance" class="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-amber-900" role="status">{{ t('credits.low') }}</p>

            <OverviewTab v-if="tab === 'overview'" @loaded="overview = $event" />
            <ProductsTab v-else-if="tab === 'products'" :languages="languages" @changed="refreshOverview" />
            <MediaTab v-else-if="tab === 'media'" />
            <SettingsTab v-else />
        </template>
    </div>
</template>
