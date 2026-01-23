<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    requirements: Object,
    allMet: Boolean
});
</script>

<template>
    <GuestLayout>
        <Head title="Instalação - Requisitos" />

        <div class="mb-4 text-center">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Bem-vindo ao Instalador</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">Verificação de requisitos do sistema</p>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
            <ul class="space-y-2">
                <li v-for="(met, req) in requirements" :key="req" class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 pb-2 last:border-0">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ req }}</span>
                    <span v-if="met" class="text-green-600 dark:text-green-400 font-bold">✔ OK</span>
                    <span v-else class="text-red-600 dark:text-red-400 font-bold">✖ Falha</span>
                </li>
            </ul>
        </div>

        <div class="flex items-center justify-end mt-4">
            <Link :href="route('install.environment')" v-if="allMet">
                <PrimaryButton>
                    Próximo: Configurar Ambiente
                </PrimaryButton>
            </Link>
            <div v-else class="text-red-500 text-sm font-bold">
                Por favor, corrija os requisitos acima para continuar.
            </div>
        </div>
    </GuestLayout>
</template>
