<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const form = useForm({
    app_url: window.location.origin,
    db_host: 'mysql', // Default for Docker/Easypanel usually
    db_port: '3306',
    db_database: 'crm_conta_azul',
    db_username: 'root',
    db_password: '',
});

const submit = () => {
    form.post(route('install.environment.save'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Instalação - Ambiente" />

        <div class="mb-4 text-center">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Configuração de Ambiente</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">Dados do Banco de Dados e Aplicação</p>
        </div>

        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <InputLabel for="app_url" value="URL da Aplicação" />
                    <TextInput id="app_url" type="url" class="mt-1 block w-full" v-model="form.app_url" required />
                    <InputError class="mt-2" :message="form.errors.app_url" />
                </div>

                <div>
                    <InputLabel for="db_host" value="Host do Banco de Dados" />
                    <TextInput id="db_host" type="text" class="mt-1 block w-full" v-model="form.db_host" required />
                    <InputError class="mt-2" :message="form.errors.db_host" />
                </div>

                <div>
                    <InputLabel for="db_port" value="Porta" />
                    <TextInput id="db_port" type="text" class="mt-1 block w-full" v-model="form.db_port" required />
                    <InputError class="mt-2" :message="form.errors.db_port" />
                </div>

                <div>
                    <InputLabel for="db_database" value="Nome do Banco de Dados" />
                    <TextInput id="db_database" type="text" class="mt-1 block w-full" v-model="form.db_database" required />
                    <InputError class="mt-2" :message="form.errors.db_database" />
                </div>

                <div>
                    <InputLabel for="db_username" value="Usuário do Banco" />
                    <TextInput id="db_username" type="text" class="mt-1 block w-full" v-model="form.db_username" required />
                    <InputError class="mt-2" :message="form.errors.db_username" />
                </div>

                <div>
                    <InputLabel for="db_password" value="Senha do Banco" />
                    <TextInput id="db_password" type="password" class="mt-1 block w-full" v-model="form.db_password" />
                    <InputError class="mt-2" :message="form.errors.db_password" />
                </div>
            </div>

            <div class="flex items-center justify-end mt-4">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Salvar e Conectar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
