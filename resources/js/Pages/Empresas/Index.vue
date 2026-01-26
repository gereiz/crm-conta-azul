<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    connections: Object,
    filters: Object,
});

const search = ref(props.filters.search || '');
const sort = ref(props.filters.sort || 'empresa_nome');
const direction = ref(props.filters.direction || 'asc');

// Debounce search
let timeout = null;
watch(search, (value) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        applyFilters();
    }, 500);
});

const applyFilters = () => {
    router.get(route('empresas.index'), {
        search: search.value,
        sort: sort.value,
        direction: direction.value,
    }, {
        preserveState: true,
        replace: true,
    });
};

const sortBy = (field) => {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = field;
        direction.value = 'asc';
    }
    applyFilters();
};

const getSortIcon = (field) => {
    if (sort.value !== field) return '↕'; // Seta neutra
    return direction.value === 'asc' ? '↑' : '↓';
};

const connectionsList = computed(() => {
    if (props.connections && props.connections.data) return props.connections.data;
    return [];
});

const getActiveMessageTypes = (settings) => {
    if (!settings) return [];
    const types = {
        'billing': { label: 'Cobrança', color: 'bg-red-100 text-red-800' },
        'due_date': { label: 'Vencimento', color: 'bg-yellow-100 text-yellow-800' },
        'boleto': { label: 'Boleto', color: 'bg-blue-100 text-blue-800' },
        'birthday': { label: 'Aniversário', color: 'bg-purple-100 text-purple-800' },
    };

    return settings
        .filter(s => s.is_enabled && types[s.message_type])
        .map(s => types[s.message_type]);
};

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
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                
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
                                    <th @click="sortBy('empresa_nome')" scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600">
                                        Empresa <span class="ml-1">{{ getSortIcon('empresa_nome') }}</span>
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Tipos de Mensagem
                                    </th>
                                    <th @click="sortBy('is_active')" scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600">
                                        Status <span class="ml-1">{{ getSortIcon('is_active') }}</span>
                                    </th>
                                    <th @click="sortBy('last_sync_at')" scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600">
                                        Última Sincronização <span class="ml-1">{{ getSortIcon('last_sync_at') }}</span>
                                    </th>
                                    <th @click="sortBy('token_expires_at')" scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600">
                                        Token expira <span class="ml-1">{{ getSortIcon('token_expires_at') }}</span>
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
                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap gap-1">
                                            <span v-for="(type, index) in getActiveMessageTypes(c.message_settings)" :key="index" 
                                                  :class="type.color" 
                                                  class="px-2 py-0.5 text-[10px] font-semibold rounded-full border border-opacity-20">
                                                {{ type.label }}
                                            </span>
                                            <span v-if="getActiveMessageTypes(c.message_settings).length === 0" class="text-xs text-gray-400">
                                                Nenhum ativo
                                            </span>
                                        </div>
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
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="connectionsList.length === 0">
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        Nenhuma empresa encontrada.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginação -->
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700" v-if="connections.links">
                         <Pagination :links="connections.links" />
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
