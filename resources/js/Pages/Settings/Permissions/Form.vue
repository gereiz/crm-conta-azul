<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    permission: Object,
});

const form = useForm({
    module: props.permission?.module || '',
    action: props.permission?.action || '',
    description: props.permission?.description || '',
});

const submit = () => {
    if (props.permission) {
        form.put(route('settings.permissions.update', props.permission.id));
    } else {
        form.post(route('settings.permissions.store'));
    }
};
</script>

<template>
    <Head :title="props.permission ? 'Editar Permissão' : 'Nova Permissão'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ props.permission ? 'Editar Permissão' : 'Nova Permissão' }}
                </h2>
                <SecondaryButton :href="route('settings.permissions.index')" as="a">Voltar</SecondaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <InputLabel for="module" value="Módulo" />
                                <TextInput id="module" v-model="form.module" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.module" />
                            </div>

                            <div>
                                <InputLabel for="action" value="Ação" />
                                <TextInput id="action" v-model="form.action" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.action" />
                            </div>

                            <div>
                                <InputLabel for="description" value="Descrição" />
                                <TextInput id="description" v-model="form.description" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.description" />
                            </div>

                            <div class="flex justify-end">
                                <SecondaryButton :href="route('settings.permissions.index')" as="a">Cancelar</SecondaryButton>
                                <PrimaryButton class="ml-3">Salvar</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
