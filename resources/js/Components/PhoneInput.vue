<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps(['modelValue']);
const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const displayValue = ref('');

const formatToMask = (value) => {
    if (!value) return '';
    const raw = value.toString();
    const hasPlus = raw.trim().startsWith('+');
    let digits = raw.replace(/\D/g, '');
    if (digits.length > 15) digits = digits.slice(0, 15);

    if (digits.length === 0) return '';

    // Formatação BR quando DDI=55 e há ao menos DDD + número
    if (digits.startsWith('55') && digits.length >= 12) {
        const cc = '+55';
        const ddd = digits.slice(2, 4);
        const rest = digits.slice(4);
        if (rest.length >= 9) {
            return `${cc} (${ddd}) ${rest.slice(0, 5)}-${rest.slice(5, 9)}`;
        }
        if (rest.length >= 8) {
            return `${cc} (${ddd}) ${rest.slice(0, 4)}-${rest.slice(4, 8)}`;
        }
        return `${cc} (${ddd}) ${rest}`;
    }

    // Internacional genérico: apenas E.164 visual (+ e dígitos), sem DDD/traços
    return (hasPlus ? '+' : '') + digits;
};

const updateDisplay = (val) => {
    displayValue.value = formatToMask(val);
};

watch(() => props.modelValue, (newVal) => {
    updateDisplay(newVal);
}, { immediate: true });

const handleInput = (event) => {
    let val = event.target.value;
    const hasPlus = val.trim().startsWith('+');
    let digits = val.replace(/\D/g, '');

    // Limit to 15 digits
    if (digits.length > 15) digits = digits.slice(0, 15);

    const out = hasPlus ? ('+' + digits) : digits;
    emit('update:modelValue', out);
    displayValue.value = formatToMask(out);
};

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        class="rounded-xl border-gray-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:focus:border-primary-500 dark:focus:ring-primary-500"
        :value="displayValue"
        @input="handleInput"
        placeholder="+DDI número (E.164) ou +55 (DD) XXXXX-XXXX"
    />
</template>
