<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { ApiError, del, get, post, put, type MediaItem, type Paginated } from '@/lib/revo/api';
import { t } from '@/lib/revo/i18n';

const items = ref<MediaItem[]>([]);
const loading = ref(true);
const status = ref('');
const search = ref('');
const folders = ref<{ id: number; name: string }[]>([]);
const folder = ref('');
const error = ref('');
const newFolder = ref('');

async function load() {
    loading.value = true;
    const query = new URLSearchParams({ search: search.value, ...(status.value ? { status: status.value } : {}), ...(folder.value ? { folder_id: folder.value } : {}) });
    items.value = (await get<Paginated<MediaItem>>(`/media?${query}`)).data;
    loading.value = false;
}

async function loadFolders() {
    folders.value = (await get<{ data: { folders: { id: number; name: string }[] } }>('/media/folders')).data.folders;
}

onMounted(() => Promise.all([load(), loadFolders()]));
watch([status, folder], load);

async function guard(action: () => Promise<unknown>) {
    error.value = '';
    try {
        await action();
        await load();
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : t('common.error');
    }
}

function upload(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const form = new FormData();
    form.append('file', file);
    if (folder.value) form.append('folder_id', folder.value);
    guard(() => post('/media', form));
}

const addFolder = () => guard(async () => {
    await post('/media/folders', { name: newFolder.value });
    newFolder.value = '';
    await loadFolders();
});
const moveTo = (item: MediaItem, id: string) => guard(() => put(`/media/${item.id}/folder`, { folder_id: id ? Number(id) : null }));
const tag = (item: MediaItem, value: string) => guard(() => put(`/media/${item.id}/tags`, { tags: value.split(',').map((s) => s.trim()).filter(Boolean) }));
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" class="rounded border px-3 py-2" :placeholder="t('common.search')" @input="load" />
            <select v-model="status" class="rounded border px-3 py-2">
                <option value="">{{ t('common.all') }}</option>
                <option value="draft">{{ t('media.drafts') }}</option>
                <option value="approved">{{ t('media.library') }}</option>
            </select>
            <select v-model="folder" class="rounded border px-3 py-2">
                <option value="">{{ t('media.folder') }}</option>
                <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
            </select>
            <input v-model="newFolder" class="rounded border px-3 py-2" placeholder="+ folder" @keyup.enter="addFolder" />
            <label class="cursor-pointer rounded border px-3 py-2">{{ t('common.upload') }}<input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="upload" /></label>
        </div>
        <p v-if="error" class="rounded border border-red-300 bg-red-50 p-3 text-red-800" role="alert">{{ error }}</p>

        <div v-if="loading" class="grid animate-pulse grid-cols-2 gap-3 md:grid-cols-4"><div v-for="n in 4" :key="n" class="h-40 rounded bg-neutral-200 dark:bg-neutral-800" /></div>
        <p v-else-if="!items.length" class="text-neutral-500">{{ t('common.empty') }}</p>
        <div v-else class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <figure v-for="item in items" :key="item.id" class="space-y-2 rounded-lg border p-2">
                <img v-if="item.url" :src="item.url" :alt="item.name ?? ''" class="aspect-square w-full rounded object-cover" loading="lazy" />
                <figcaption class="truncate text-sm">{{ item.name ?? `#${item.id}` }} · {{ item.status }}</figcaption>
                <select class="w-full rounded border px-1 py-1 text-sm" @change="moveTo(item, ($event.target as HTMLSelectElement).value)">
                    <option value="">{{ t('media.folder') }}</option>
                    <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
                <input :value="item.tags.join(', ')" class="w-full rounded border px-1 py-1 text-sm" :placeholder="t('media.tags')" @change="tag(item, ($event.target as HTMLInputElement).value)" />
                <div class="flex flex-wrap gap-1">
                    <button v-if="item.status === 'draft'" class="rounded border px-2 py-1 text-xs" @click="guard(() => post(`/media/${item.id}/approve`))">{{ t('common.approve') }}</button>
                    <button v-if="item.status === 'draft'" class="rounded border px-2 py-1 text-xs" @click="guard(() => post(`/media/${item.id}/discard`))">{{ t('common.reject') }}</button>
                    <button class="rounded border px-2 py-1 text-xs text-red-700" @click="guard(() => del(`/media/${item.id}`))">{{ t('common.delete') }}</button>
                </div>
            </figure>
        </div>
    </div>
</template>
