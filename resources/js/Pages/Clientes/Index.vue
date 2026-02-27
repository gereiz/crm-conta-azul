<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    clientes: Object, // Paginator Laravel
    filters: Object,
    connections: Array,
});

const search = ref(props.filters.search || '');
const currentSort = ref(props.filters.sort || 'name');
const currentDirection = ref(props.filters.direction || 'asc');
const connectionId = ref(props.filters.connection_id || '');
const noPhone = ref(Boolean(props.filters.no_phone || false));
const noEmail = ref(Boolean(props.filters.no_email || false));
const noDocument = ref(Boolean(props.filters.no_document || false));
const onlyInternational = ref(
    props.filters.international === true || props.filters.international === '1'
);

// Função para acionar a ordenação
const sort = (field) => {
    if (currentSort.value === field) {
        currentDirection.value = currentDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        currentSort.value = field;
        currentDirection.value = 'asc';
    }
    
    updateParams();
};

const updateParams = () => {
    router.get(route('clientes.index'), { 
        search: search.value, 
        sort: currentSort.value,
        direction: currentDirection.value,
        connection_id: connectionId.value || '',
        no_phone: noPhone.value ? '1' : '',
        no_email: noEmail.value ? '1' : '',
        no_document: noDocument.value ? '1' : '',
        international: onlyInternational.value ? '1' : '',
        page: 1 // Resetar página ao ordenar ou filtrar
    }, {
        preserveState: true,
        replace: true,
    });
};

// Debounce search
let timeout = null;
watch(search, (value) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        router.get(route('clientes.index'), { 
            search: value, 
            page: 1,
            sort: currentSort.value,
            direction: currentDirection.value,
            connection_id: connectionId.value || '',
            no_phone: noPhone.value ? '1' : '',
            no_email: noEmail.value ? '1' : '',
            no_document: noDocument.value ? '1' : '',
            international: onlyInternational.value ? '1' : '',
        }, {
            preserveState: true,
            replace: true,
        });
    }, 500);
});

const clientesList = computed(() => props.clientes.data || []);

// Paginação
const currentPage = computed(() => props.clientes.current_page || 1);
const totalPages = computed(() => props.clientes.last_page || 1);
const hasPrev = computed(() => props.clientes.prev_page_url !== null);
const hasNext = computed(() => props.clientes.next_page_url !== null);

const changePage = (page) => {
    router.get(route('clientes.index'), { 
        search: search.value, 
        page: page,
        sort: currentSort.value,
        direction: currentDirection.value,
        connection_id: connectionId.value || '',
        no_phone: noPhone.value ? '1' : '',
        no_email: noEmail.value ? '1' : '',
            no_document: noDocument.value ? '1' : '',
            international: onlyInternational.value ? '1' : '',
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const exportClients = () => {
    const params = new URLSearchParams({
        search: search.value || '',
        sort: currentSort.value || 'name',
        direction: currentDirection.value || 'asc',
        connection_id: connectionId.value || '',
        no_phone: noPhone.value ? '1' : '',
        no_email: noEmail.value ? '1' : '',
        no_document: noDocument.value ? '1' : '',
        international: onlyInternational.value ? '1' : '',
    });
    window.location.href = route('clientes.export') + '?' + params.toString();
};

const toggling = ref({});
const toggleInternational = async (cliente) => {
    if (!cliente || !cliente.id) return;
    if (toggling.value[cliente.id]) return;
    toggling.value[cliente.id] = true;
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const { data } = await axios.post(route('clientes.toggle_international', cliente.id), {}, { headers: { 'X-CSRF-TOKEN': csrf } });
        // Atualiza localmente
        const list = clientesList.value;
        const idx = list.findIndex(c => c.id === cliente.id);
        if (idx >= 0) {
            list[idx].is_international = data.is_international;
        }
    } catch (e) {
        alert('Falha ao alterar internacional: ' + (e?.response?.data?.error || e.message));
    } finally {
        toggling.value[cliente.id] = false;
    }
};

</script>

<template>
    <Head title="Clientes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Clientes (Conta Azul)
                </h2>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                
                <!-- Filtros -->
                <div class="mb-6 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-4">
                    <div class="flex flex-col md:flex-row md:items-end gap-4">
                        <TextInput
                            v-model="search"
                            type="text"
                            placeholder="Buscar por nome, telefone..."
                            class="block w-full md:w-1/3"
                        />
                        <div class="w-full md:w-1/4">
                            <label class="block text-xs mb-1 text-gray-600 dark:text-gray-300">Empresa</label>
                            <select v-model="connectionId" @change="updateParams" class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md text-sm">
                                <option value="">Todas</option>
                                <option v-for="c in (connections || [])" :key="c.id" :value="c.id">{{ c.empresa_nome }}</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="inline-flex items-center text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox" v-model="noPhone" @change="updateParams" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" />
                                <span class="ml-2">Sem telefone</span>
                            </label>
                            <label class="inline-flex items-center text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox" v-model="noEmail" @change="updateParams" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" />
                                <span class="ml-2">Sem e-mail</span>
                            </label>
                            <label class="inline-flex items-center text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox" v-model="noDocument" @change="updateParams" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" />
                                <span class="ml-2">Sem documento</span>
                            </label>
                            <label class="inline-flex items-center text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox" v-model="onlyInternational" @change="updateParams" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" />
                                <span class="ml-2">Somente internacionais</span>
                            </label>
                        </div>
                        <div class="flex-1"></div>
                        <PrimaryButton @click="exportClients" class="text-xs">Exportar Clientes</PrimaryButton>
                    </div>
                </div>

                <!-- Tabela -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600 select-none" @click="sort('name')">
                                        <div class="flex items-center gap-1">
                                            Nome
                                            <span v-if="currentSort === 'name'" class="text-gray-900 dark:text-white">{{ currentDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600 select-none" @click="sort('company_name')">
                                        <div class="flex items-center gap-1">
                                            Empresa / Documento
                                            <span v-if="currentSort === 'company_name'" class="text-gray-900 dark:text-white">{{ currentDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600 select-none" @click="sort('mobile_phone')">
                                        <div class="flex items-center gap-1">
                                            Telefone / WhatsApp
                                            <span v-if="currentSort === 'mobile_phone'" class="text-gray-900 dark:text-white">{{ currentDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600 select-none" @click="sort('is_international')">
                                        <div class="flex items-center gap-1">
                                            Internacional
                                            <span v-if="currentSort === 'is_international'" class="text-gray-900 dark:text-white">{{ currentDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </div>
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                        Ações
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="cliente in clientesList" :key="cliente.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ cliente.name }}</div>
                                        <div class="text-sm text-gray-500">{{ cliente.email }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100">{{ cliente.company_name }}</div>
                                        <div class="text-sm text-gray-500">{{ cliente.cpf_cnpj }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100">{{ cliente.mobile_phone || cliente.phone }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <button @click="toggleInternational(cliente)" class="inline-flex items-center px-2 py-1 text-xs rounded-full"
                                            :class="cliente.is_international ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'">
                                            {{ cliente.is_international ? 'Sim' : 'Não' }}
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link :href="route('clientes.show', cliente.id)" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 mr-2">
                                            Detalhes
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="clientesList.length === 0">
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        Nenhum cliente encontrado. <br>
                                        <span class="text-xs">Certifique-se de sincronizar os clientes em Configurações > Conta Azul.</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Paginação -->
                <div class="mt-4 flex justify-between items-center" v-if="totalPages > 1">
                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        Página {{ currentPage }} de {{ totalPages }}
                    </div>
                    <div class="flex gap-2">
                        <SecondaryButton 
                            :disabled="!hasPrev" 
                            @click="changePage(currentPage - 1)"
                            class="px-3 py-1"
                            :class="{ 'opacity-50 cursor-not-allowed': !hasPrev }"
                        >
                            Anterior
                        </SecondaryButton>
                        <SecondaryButton 
                            :disabled="!hasNext" 
                            @click="changePage(currentPage + 1)"
                            class="px-3 py-1"
                            :class="{ 'opacity-50 cursor-not-allowed': !hasNext }"
                        >
                            Próxima
                        </SecondaryButton>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
