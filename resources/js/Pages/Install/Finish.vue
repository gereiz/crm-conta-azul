<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
</script>

<template>
    <GuestLayout>
        <Head title="Instalação Concluída" />

        <div class="mb-4 text-center">
            <h2 class="text-xl font-bold text-green-600">Instalação Concluída!</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">O sistema foi configurado com sucesso.</p>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow mb-6 text-center">
            <svg class="w-16 h-16 text-green-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <p class="text-gray-700 dark:text-gray-300">
                O arquivo de trava de instalação (storage/installed) foi criado para proteger seu sistema.
            </p>
        </div>

        <div class="flex items-center justify-center mt-4">
            <Link :href="route('login')">
                <PrimaryButton>
                    Ir para o Login
                </PrimaryButton>
            </Link>
        </div>
    </GuestLayout>
</template>
