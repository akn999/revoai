<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { ApiError, get, post, put, del } from '@/lib/revo/api';
import { t } from '@/lib/revo/i18n';

interface Snapshot {
    general: { languages: string[]; enabled_languages: string[]; auto_publish: boolean; description_length: string; description_structure: string };
    prompts: Record<string, { label: string; default: string; override: string | null; effective: string }>;
    store_context: Record<string, unknown>;
    presets: { id: number; name_en: string; name_ar: string; prompt: string; default: boolean }[];
}

const snapshot = ref<Snapshot | null>(null);
const general = reactive({ languages: [] as string[], auto_publish: false, description_length: 'medium', description_structure: 'paragraphs' });
const promptDrafts = reactive<Record<string, string>>({});
const context = reactive<Record<string, string>>({ store_name: '', tagline: '', slogan: '', delivery_policy: '', return_policy: '', privacy_policy: '' });
const preset = reactive({ name_ar: '', name_en: '', prompt: '' });
const message = ref('');
const error = ref('');

function apply(data: Snapshot) {
    snapshot.value = data;
    Object.assign(general, { languages: data.general.languages, auto_publish: data.general.auto_publish, description_length: data.general.description_length, description_structure: data.general.description_structure });
    for (const [key, p] of Object.entries(data.prompts)) promptDrafts[key] = p.effective;
    for (const key of Object.keys(context)) context[key] = (data.store_context[key] as string) ?? '';
}

onMounted(async () => apply((await get<{ data: Snapshot }>('/settings')).data));

async function run(action: () => Promise<{ data: Snapshot } | unknown>) {
    error.value = '';
    message.value = '';
    try {
        const res = (await action()) as { data?: Snapshot } | undefined;
        if (res?.data && 'general' in res.data) apply(res.data);
        message.value = t('common.saved');
    } catch (e) {
        error.value = e instanceof ApiError ? Object.values((e.body.errors as Record<string, string[]>) ?? {})[0]?.[0] ?? e.message : t('common.error');
    }
}

const saveGeneral = () => run(() => put('/settings/general', general));
const savePrompt = (key: string) => run(() => put(`/settings/prompts/${key}`, { body: promptDrafts[key] }));
const resetPrompt = (key: string) => run(() => put(`/settings/prompts/${key}`, { body: null }));
const saveContext = () => run(() => put('/settings/context', context));
const addPreset = () => run(async () => { await post('/settings/presets', preset); Object.assign(preset, { name_ar: '', name_en: '', prompt: '' }); return get('/settings'); });
const removePreset = (id: number) => run(async () => { await del(`/settings/presets/${id}`); return get('/settings'); });

// Autosave the context form so a reload loses nothing (FR-PLT-010).
let timer: ReturnType<typeof setTimeout>;
watch(context, () => {
    clearTimeout(timer);
    timer = setTimeout(() => put('/drafts/settings.context', { payload: { ...context } }).catch(() => undefined), 2000);
}, { deep: true });
</script>

<template>
    <div v-if="!snapshot" class="animate-pulse space-y-3"><div class="h-32 rounded bg-neutral-200 dark:bg-neutral-800" /></div>
    <div v-else class="space-y-8">
        <p v-if="message" class="rounded border border-green-300 bg-green-50 p-2 text-green-800" role="status">{{ message }}</p>
        <p v-if="error" class="rounded border border-red-300 bg-red-50 p-2 text-red-800" role="alert">{{ error }}</p>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ t('settings.general') }}</h2>
            <fieldset>
                <legend class="text-sm">{{ t('settings.languages') }}</legend>
                <label v-for="l in snapshot.general.enabled_languages" :key="l" class="me-4 inline-flex gap-1"><input v-model="general.languages" type="checkbox" :value="l" /> {{ l }}</label>
            </fieldset>
            <label class="flex items-center gap-2"><input v-model="general.auto_publish" type="checkbox" /> {{ t('settings.autoPublish') }}</label>
            <label class="block text-sm">{{ t('settings.length') }}
                <select v-model="general.description_length" class="mt-1 block rounded border px-3 py-2"><option>short</option><option>medium</option><option>long</option></select>
            </label>
            <label class="block text-sm">{{ t('settings.structure') }}
                <select v-model="general.description_structure" class="mt-1 block rounded border px-3 py-2"><option>paragraphs</option><option>bullets</option></select>
            </label>
            <button class="rounded bg-neutral-900 px-4 py-2 text-white dark:bg-white dark:text-neutral-900" @click="saveGeneral">{{ t('common.save') }}</button>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ t('settings.prompts') }}</h2>
            <div v-for="(p, key) in snapshot.prompts" :key="key" class="space-y-1">
                <label class="text-sm font-medium" :for="`p-${key}`">{{ p.label }}</label>
                <textarea :id="`p-${key}`" v-model="promptDrafts[key]" rows="4" class="block w-full rounded border px-3 py-2" />
                <button class="me-2 rounded border px-3 py-1" @click="savePrompt(key)">{{ t('common.save') }}</button>
                <button v-if="p.override !== null" class="rounded border px-3 py-1" @click="resetPrompt(key)">{{ t('common.reset') }}</button>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ t('settings.context') }}</h2>
            <label v-for="(_, key) in context" :key="key" class="block text-sm">{{ key }}
                <textarea v-model="context[key]" rows="2" maxlength="5000" class="mt-1 block w-full rounded border px-3 py-2" />
            </label>
            <button class="rounded bg-neutral-900 px-4 py-2 text-white dark:bg-white dark:text-neutral-900" @click="saveContext">{{ t('common.save') }}</button>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ t('settings.presets') }}</h2>
            <ul class="divide-y rounded border">
                <li v-for="p in snapshot.presets" :key="p.id" class="flex items-center justify-between p-2">
                    <span>{{ p.name_en }} / {{ p.name_ar }}</span>
                    <button v-if="!p.default" class="text-sm text-red-700" @click="removePreset(p.id)">{{ t('common.delete') }}</button>
                </li>
            </ul>
            <input v-model="preset.name_en" class="block w-full rounded border px-3 py-2" placeholder="Name (English)" />
            <input v-model="preset.name_ar" class="block w-full rounded border px-3 py-2" placeholder="الاسم (العربية)" dir="rtl" />
            <textarea v-model="preset.prompt" rows="3" class="block w-full rounded border px-3 py-2" placeholder="Prompt" />
            <button class="rounded border px-4 py-2" @click="addPreset">{{ t('common.save') }}</button>
        </section>
    </div>
</template>
