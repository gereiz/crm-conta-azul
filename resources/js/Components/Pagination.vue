<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: Array,
});
</script>

<template>
    <div v-if="links.length > 3" class="flex justify-center mt-4">
        <div class="flex flex-wrap -mb-1 gap-1">
            <template v-for="(link, k) in links" :key="k">
                <div v-if="link.url === null" class="px-4 py-2 text-sm text-gray-500 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 opacity-50" v-html="link.label" />
                <Link v-else class="px-4 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300 transition-colors" :class="{ 'bg-primary-50 border-primary-500 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400': link.active, 'bg-white dark:bg-gray-800': !link.active }" :href="link.url" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
