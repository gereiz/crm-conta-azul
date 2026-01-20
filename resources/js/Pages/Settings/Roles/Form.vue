<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    role: Object,
    permissions: Array,
    selected: Array
});

const form = useForm({
    name: props.role?.name || '',
    description: props.role?.description || '',
    is_active: props.role?.is_active ?? true,
    permission_ids: props.selected || [],
});

const submit = () => {
    if (props.role) {
        form.put(route('settings.roles.update', props.role.id));
    } else {
        form.post(route('settings.roles.store'));
    }
};
</script>

<template>
    <Head :title="props.role ? 'Editar Perfil' : 'Novo Perfil'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ props.role ? 'Editar Perfil' : 'Novo Perfil' }}
                </h2>
                <SecondaryButton :href="route('settings.roles.index')" as="a">Voltar</SecondaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <InputLabel for="name" value="Nome" />
                                <TextInput id="name" v-model="form.name" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.name" />
                            </div>

                            <div>
                                <InputLabel for="description" value="Descrição" />
                                <TextInput id="description" v-model="form.description" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.description" />
                            </div>

                            <div class="flex items-center">
                                <input id="is_active" type="checkbox" v-model="form.is_active" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:ring-primary-500" />
                                <label for="is_active" class="ml-2 text-sm text-gray-700 dark:text-gray-300">Ativo</label>
                            </div>

                            <div>
                                <InputLabel value="Permissões" />
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                                    <label v-for="perm in permissions" :key="perm.id" class="inline-flex items-center">
                                        <input type="checkbox" :value="perm.id" v-model="form.permission_ids" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:ring-primary-500" />
                                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ perm.module }}.{{ perm.action }} — {{ perm.description }}</span>
                                    </label>
                                </div>
                                <InputError class="mt-2" :message="form.errors.permission_ids" />
                            </div>

                            <div class="flex justify-end">
                                <SecondaryButton :href="route('settings.roles.index')" as="a">Cancelar</SecondaryButton>
                                <PrimaryButton class="ml-3">Salvar</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
