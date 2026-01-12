<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    connections: Object,
    filters: Object,
});

const search = ref(props.filters.search || '');

// Debounce search
let timeout = null;
watch(search, (value) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        router.get(route('empresas.index'), { search: value }, {
            preserveState: true,
            replace: true,
        });
    }, 500);
});

const getConnectionsList = () => {
    if (Array.isArray(props.connections)) return props.connections;
    if (props.connections && props.connections.data) return props.connections.data;
    return [];
};

const connectionsList = getConnectionsList();
</script>

<template>
    <Head title="Empresas" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Empresas (Conexões Conta Azul)
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                
                <!-- Filtros -->
                <div class="mb-6 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-4">
                    <div class="flex gap-4">
                        <TextInput
                            v-model="search"
                            type="text"
                            placeholder="Buscar por nome da empresa"
                            class="block w-full md:w-1/3"
                        />
                    </div>
                </div>

                <!-- Tabela -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Empresa
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Última Sincronização
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Token expira
                                    </th>
                                    <th scope="col" class="relative px-6 py-3 text-right">
                                        <span class="sr-only">Ações</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="c in connectionsList" :key="c.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ c.empresa_nome }}</div>
                                        <div class="text-xs text-gray-500">{{ c.email_desenvolvedor || '—' }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="c.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ c.is_active ? 'Ativa' : 'Inativa' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ c.last_sync_at ? new Date(c.last_sync_at).toLocaleString('pt-BR') : '—' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ c.token_expires_at ? new Date(c.token_expires_at).toLocaleString('pt-BR') : '—' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex gap-3 justify-end">
                                            <Link :href="route('empresas.show', c.id)" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200">
                                                Detalhes
                                            </Link>
                                            <a :href="route('contaazul.connections.connect', { connection: c.id })" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                                Conectar
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="connectionsList.length === 0">
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        Nenhuma empresa encontrada.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
