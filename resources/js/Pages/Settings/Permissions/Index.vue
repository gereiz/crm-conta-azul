<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    permissions: {
        type: Array,
        required: true
    }
});

const destroyPermission = (perm) => {
    if (confirm(`Excluir a permissão ${perm.module || '-'}.${perm.action || '-'}?`)) {
        router.delete(route('settings.permissions.destroy', perm.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Permissões" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Permissões
                </h2>
                <Link :href="route('settings.permissions.create')" class="inline-flex items-center px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700">
                    Nova Permissão
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div v-if="permissions.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            Nenhuma permissão cadastrada.
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Módulo</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ação</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Descrição</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="perm in permissions" :key="perm.id">
                                        <td class="px-6 py-4 whitespace-nowrap">{{ perm.module || '-' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">{{ perm.action || '-' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">{{ perm.description || '-' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <Link :href="route('settings.permissions.edit', perm.id)" class="text-primary-600 hover:text-primary-900 mr-3">
                                                Editar
                                            </Link>
                                            <button @click="destroyPermission(perm)" class="text-red-600 hover:text-red-900">
                                                Excluir
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
    </template
