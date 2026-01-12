<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps(['modelValue']);
const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const displayValue = ref('');

const formatToMask = (value) => {
    if (!value) return '';
    let digits = value.toString().replace(/\D/g, '');
    if (digits.length > 15) digits = digits.slice(0, 15);
    
    if (digits.length === 0) return '';
    
    if (digits.length <= 2) {
        return '+' + digits;
    }
    if (digits.length <= 4) {
        return '+' + digits.slice(0, 2) + ' (' + digits.slice(2);
    }
    if (digits.length <= 8) {
        return '+' + digits.slice(0, 2) + ' (' + digits.slice(2, 4) + ') ' + digits.slice(4);
    }
    if (digits.length <= 12) {
        // Landline or incomplete mobile
        return '+' + digits.slice(0, 2) + ' (' + digits.slice(2, 4) + ') ' + digits.slice(4, 8) + '-' + digits.slice(8);
    }
    // Mobile (13+ digits)
    return '+' + digits.slice(0, 2) + ' (' + digits.slice(2, 4) + ') ' + digits.slice(4, 9) + '-' + digits.slice(9);
};

const updateDisplay = (val) => {
    displayValue.value = formatToMask(val);
};

watch(() => props.modelValue, (newVal) => {
    updateDisplay(newVal);
}, { immediate: true });

const handleInput = (event) => {
    let val = event.target.value;
    let digits = val.replace(/\D/g, '');
    
    // Limit to 15 digits
    if (digits.length > 15) digits = digits.slice(0, 15);
    
    emit('update:modelValue', digits);
    displayValue.value = formatToMask(digits);
};

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        class="rounded-xl border-gray-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:focus:border-primary-500 dark:focus:ring-primary-500"
        :value="displayValue"
        @input="handleInput"
        placeholder="(XX) XXXXX-XXXX"
    />
</template>
