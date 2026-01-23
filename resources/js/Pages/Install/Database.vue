<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const form = useForm({});

const submit = () => {
    form.post(route('install.migrate'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Instalação - Banco de Dados" />

        <div class="mb-4 text-center">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Instalação do Banco de Dados</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">Criar tabelas e dados iniciais</p>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow mb-6 text-center">
            <p class="text-gray-700 dark:text-gray-300 mb-4">
                O arquivo de configuração foi salvo com sucesso. Agora estamos prontos para criar as tabelas do sistema e popular com os dados iniciais.
            </p>
            <p class="text-sm text-gray-500 mb-4">
                Este processo pode levar alguns segundos.
            </p>

            <div v-if="form.errors.message" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
                {{ form.errors.message }}
            </div>
        </div>

        <div class="flex items-center justify-center mt-4">
            <form @submit.prevent="submit">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Instalar Banco de Dados
                </PrimaryButton>
            </form>
        </div>
    </GuestLayout>
</template>
