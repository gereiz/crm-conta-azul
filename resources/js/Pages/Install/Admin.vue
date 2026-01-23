<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const form = useForm({
    name: 'Administrador',
    email: 'admin@exemplo.com',
    password: '',
    password_confirmation: '',
});

const submit = () => {
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

            <div class="flex items-center justify-end mt-4">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Criar Administrador e Finalizar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
