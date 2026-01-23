<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    hasAdmin: Boolean
});

const form = useForm({
    name: 'Administrador',
    email: 'admin@exemplo.com',
    password: '',
    password_confirmation: '',
    skip: false,
});

const submit = () => {
    form.post(route('install.admin.store'));
};

const skip = () => {
    form.skip = true;
    form.post(route('install.admin.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Instalação - Administrador" />

        <div class="mb-4 text-center">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Criar Conta de Administrador</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">Configure o acesso principal do sistema</p>
        </div>

        <div v-if="hasAdmin" class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative mb-4">
            <strong class="font-bold">Info:</strong>
            <span class="block sm:inline"> Já existe um administrador cadastrado no banco de dados.</span>
            <p class="mt-2 text-sm">
                Se desejar, você pode pular esta etapa e usar as credenciais existentes.
            </p>
        </div>

        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <InputLabel for="name" value="Nome Completo" />
                    <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autofocus />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="email" value="E-mail" />
                    <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <InputLabel for="password" value="Senha" />
                    <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <InputLabel for="password_confirmation" value="Confirmar Senha" />
                    <TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation" required />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>
            </div>

            <div class="flex items-center justify-end mt-4 gap-3">
                <button 
                    v-if="hasAdmin"
                    type="button" 
                    @click="skip"
                    class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 focus:bg-gray-300 active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    :disabled="form.processing"
                >
                    Pular Criação
                </button>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Criar Administrador e Finalizar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
