<script setup>
import { ref, watch } from 'vue';
import Sidebar from '@/Components/Sidebar.vue';
import { usePage } from '@inertiajs/vue3';

const sidebarOpen = ref(true);
const page = usePage();
const toast = ref({ visible: false, message: '', type: 'success' });
let toastTimeout = null;
const flashSuccessVisible = ref(false);
const flashErrorVisible = ref(false);

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};

watch(() => page.props.flash, (flash) => {
    const success = typeof flash?.success === 'function' ? flash.success() : flash?.success;
    const error = typeof flash?.error === 'function' ? flash.error() : flash?.error;
    const message = typeof flash?.message === 'function' ? flash.message() : flash?.message;
    const finalMessage = success || error || message;
    const type = error ? 'error' : 'success';
    flashSuccessVisible.value = !!success;
    flashErrorVisible.value = !!error;
    if (finalMessage) {
        toast.value = { visible: true, message: finalMessage, type };
        clearTimeout(toastTimeout);
        toastTimeout = setTimeout(() => {
            toast.value.visible = false;
        }, 4000);
    }
}, { deep: true });
</script>

<template>
    <div class="flex min-h-screen bg-gray-100 dark:bg-gray-900">
        <!-- Sidebar -->
        <Sidebar :open="sidebarOpen" />

        <div class="flex-1 flex flex-col min-h-screen overflow-hidden transition-all duration-300">
            <!-- Toast -->
            <div v-if="toast.visible" class="fixed top-4 right-4 z-50">
                <div :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'" class="relative text-white px-4 py-3 rounded shadow-lg">
                    <button @click="toast.visible = false" class="absolute top-1 right-1 text-white/80 hover:text-white">
                        ×
                    </button>
                    <span>{{ toast.message }}</span>
                </div>
            </div>
            <!-- Top Header -->
            <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-100 dark:border-gray-700 h-16 flex items-center justify-between px-6 shrink-0">
                <!-- Hamburger Button -->
                <button @click="toggleSidebar" class="text-gray-500 hover:text-gray-700 focus:outline-none lg:hidden">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                
                <!-- Title / Breadcrumbs (Optional) -->
                <div class="flex-1 px-4">
                     <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 dark:bg-gray-900 p-6">
                <!-- Flash Messages -->
                <div v-if="flashSuccessVisible && $page.props.flash.success" class="relative mb-4 p-4 text-sm text-green-700 bg-green-100 rounded-lg dark:bg-green-200 dark:text-green-800" role="alert">
                    <button @click="flashSuccessVisible = false" class="absolute top-2 right-2 text-green-700/70 hover:text-green-700">×</button>
                    <span class="font-medium">Sucesso!</span> {{ $page.props.flash.success }}
                </div>
                <div v-if="flashErrorVisible && $page.props.flash.error" class="relative mb-4 p-4 text-sm text-red-700 bg-red-100 rounded-lg dark:bg-red-200 dark:text-red-800" role="alert">
                    <button @click="flashErrorVisible = false" class="absolute top-2 right-2 text-red-700/70 hover:text-red-700">×</button>
                    <span class="font-medium">Erro!</span> {{ $page.props.flash.error }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
