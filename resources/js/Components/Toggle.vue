<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: [Boolean, Number, String],
        default: false,
    },
    label: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const proxyChecked = computed({
    get() {
        return props.modelValue;
    },
    set(val) {
        emit('update:modelValue', val);
    },
});
</script>

<template>
    <div class="flex items-center">
        <button
            type="button"
            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
            :class="[proxyChecked ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700']"
            role="switch"
            :aria-checked="proxyChecked"
            @click="proxyChecked = !proxyChecked"
        >
            <span
                aria-hidden="true"
                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                :class="[proxyChecked ? 'translate-x-5' : 'translate-x-0']"
            />
        </button>
        <span v-if="label" class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300" @click="proxyChecked = !proxyChecked">
            {{ label }}
        </span>
    </div>
</template>
