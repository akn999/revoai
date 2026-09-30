<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { embedded } from '@salla.sa/embedded-sdk';
import { onMounted } from 'vue';

onMounted(async () => {
    try {
        const { layout } = await embedded.init({ debug: true });

        document.documentElement.classList.toggle('dark', layout.theme === 'dark');
        document.documentElement.lang = layout.locale;
        document.documentElement.dir = layout.dir;

        embedded.page.setTitle('Hello World');
        embedded.ready();
    } catch (error) {
        console.error('Salla embedded SDK initialization failed', error);
    }
});
</script>

<template>
    <Head title="Hello World" />
    <div class="flex min-h-screen items-center justify-center bg-white text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <h1 class="text-3xl font-semibold">Hello World</h1>
    </div>
</template>
