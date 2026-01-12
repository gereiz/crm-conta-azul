<script setup>
import { computed } from 'vue';
const props = defineProps({
    type: { type: String, default: 'button' },
    size: { type: String, default: 'md' },
});
const sizeClass = computed(() => {
    if (props.size === 'xs') return 'px-3 py-1 text-xs rounded-full';
    if (props.size === 'sm') return 'px-3 py-1.5 text-sm rounded-xl';
    return 'px-4 py-2 text-sm rounded-xl';
});
</script>

<template>
    <button
        :type="type"
        :class="[
            'inline-flex items-center border border-primary-200 bg-white font-medium text-primary-700 shadow-sm transition duration-150 ease-in-out hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 dark:border-primary-500/40 dark:bg-gray-800 dark:text-primary-300 dark:hover:bg-gray-700 dark:focus:ring-offset-gray-900',
            sizeClass
        ]"
    >
        <slot />
    </button>
</template>
