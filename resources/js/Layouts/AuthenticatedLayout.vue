<script setup>
import { ref } from 'vue';
import Sidebar from '@/Components/Sidebar.vue';
import { usePage } from '@inertiajs/vue3';

const sidebarOpen = ref(true);

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};
</script>

<template>
    <div class="flex min-h-screen bg-gray-100 dark:bg-gray-900">
        <!-- Sidebar -->
        <Sidebar :open="sidebarOpen" />

        <div class="flex-1 flex flex-col min-h-screen overflow-hidden transition-all duration-300">
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
                <div v-if="$page.props.flash.success" class="mb-4 p-4 text-sm text-green-700 bg-green-100 rounded-lg dark:bg-green-200 dark:text-green-800" role="alert">
                    <span class="font-medium">Sucesso!</span> {{ $page.props.flash.success }}
                </div>
                <div v-if="$page.props.flash.error" class="mb-4 p-4 text-sm text-red-700 bg-red-100 rounded-lg dark:bg-red-200 dark:text-red-800" role="alert">
                    <span class="font-medium">Erro!</span> {{ $page.props.flash.error }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>