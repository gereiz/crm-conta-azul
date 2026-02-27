<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    reports: Object,
    connections: Array,
    selectedConnectionId: Number,
    selectedStartDate: String,
    selectedEndDate: String,
    search: String,
    selectedType: String,
    statuses: Array,
    selectedStatus: String,
});

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString('pt-BR');
};

const getDownloadUrl = (report) => {
    return route('whatsapp.reports.download', { id: report.id });
};
const getClientDownloadUrl = (report) => {
    const params = {
        connection_id: report.connection_id,
        cliente_id: report.cliente_id,
        start_date: startDate.value || '',
        end_date: endDate.value || '',
        type: selectedType.value || '',
    };
    return route('whatsapp.reports.download_client', params);
};

const getGroupedCronDownloadUrl = () => {
    const params = {};
    if (startDate.value) params.start_date = startDate.value;
    if (endDate.value) params.end_date = endDate.value;
    if (selectedConnectionId.value) params.connection_id = selectedConnectionId.value;
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedType.value) params.type = selectedType.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    return route('whatsapp.reports.cron.download_grouped', params);
};
const getClientReportDownloadUrl = () => {
    const params = {};
    if (startDate.value) params.start_date = startDate.value;
    if (endDate.value) params.end_date = endDate.value;
    if (selectedConnectionId.value) params.connection_id = selectedConnectionId.value;
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedType.value) params.type = selectedType.value;
    return route('whatsapp.reports.download_client', params);
};
const selectedConnectionId = ref(props.selectedConnectionId || '');
const startDate = ref(props.selectedStartDate || '');
const endDate = ref(props.selectedEndDate || '');
const searchQuery = ref(props.search || '');
const selectedType = ref(props.selectedType || '');
const selectedStatus = ref(props.selectedStatus || '');

const filterByConnection = () => {
    const params = {};
    if (selectedConnectionId.value) {
        params.connection_id = selectedConnectionId.value;
    }
    if (startDate.value) params.start_date = startDate.value;
    if (endDate.value) params.end_date = endDate.value;
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedType.value) params.type = selectedType.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    
    router.get(route('whatsapp.reports.index'), params, { preserveScroll: true, replace: true });
};

const applySearch = () => {
    filterByConnection();
};

const formatWhatsAppNumber = (n) => {
    if (!n) return '-';
    const ddi = n.ddi || '';
    const ddd = n.ddd || '';
    const phone = n.phone || '';
    const parts = [ddi, ddd, phone].filter(Boolean);
    return parts.join(' ');
};
</script>

<template>
    <Head title="Relatórios de Envio" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Relatórios de Envio
                </h2>
                <div class="flex items-center gap-3">
                    <input 
                        type="text" 
                        v-model="searchQuery" 
                        @keyup.enter="applySearch"
                        placeholder="Buscar..." 
                        class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300 w-32" 
                    />
                    <input type="date" v-model="startDate" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300" />
                    <input type="date" v-model="endDate" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300" />
                    <button @click="filterByConnection" class="inline-flex items-center px-3 py-2 text-xs font-medium rounded-md bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600">
                        Filtrar
                    </button>
                    <a :href="getGroupedCronDownloadUrl()" target="_blank" class="inline-flex items-center px-3 py-2 text-xs font-medium rounded-md bg-primary-600 text-white hover:bg-primary-700">
                        Baixar Automação (XLSX Agrupado)
                    </a>
                    <a :href="getClientReportDownloadUrl()" target="_blank" class="inline-flex items-center px-3 py-2 text-xs font-medium rounded-md bg-purple-600 text-white hover:bg-purple-700">
                        Baixar Relatório Cliente
                    </a>
                    <select v-if="connections && connections.length" v-model="selectedConnectionId" @change="filterByConnection" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                        <option value="">Todas as Empresas</option>
                        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.empresa_nome }}</option>
                    </select>
                    <select v-model="selectedType" @change="filterByConnection" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                        <option value="">Todos os Tipos</option>
                        <option value="billing">Cobrança</option>
                        <option value="boleto">Emissão</option>
                        <option value="due_date">Vencimento</option>
                    </select>
                    <select v-model="selectedStatus" @change="filterByConnection" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                        <option value="">Todos os Status</option>
                        <option v-for="s in (statuses || [])" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-screen-2xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Data
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Tipo
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Cliente / Empresa
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Telefone (Sanitizado)
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            WhatsApp
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Template
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Boletos
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            Ações
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="report in reports.data" :key="report.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ formatDate(report.sent_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span v-if="report.message_type === 'manual'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                Manual
                                            </span>
                                            <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                                {{ report.message_type }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <div class="flex flex-col">
                                                <template v-if="report.message_type === 'manual'">
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                                        {{
                                                            ((report.client_name && report.client_name.toLowerCase() !== 'manual')
                                                                ? report.client_name
                                                                : 'Cliente não identificado') + ' - manual'
                                                        }}
                                                    </span>
                                                    <span v-if="report.connection?.empresa_nome" class="text-xs text-gray-500">
                                                        {{ report.connection.empresa_nome }}
                                                    </span>
                                                </template>
                                                <template v-else>
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ report.client_name || 'Desconhecido' }}</span>
                                                    <span class="text-xs text-gray-500">{{ report.connection?.empresa_nome }}</span>
                                                </template>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <div class="flex flex-col">
                                                <span class="font-mono">{{ report.phone_sanitized || '-' }}</span>
                                                <span class="text-xs text-gray-400" v-if="report.phone_original !== report.phone_sanitized">Orig: {{ report.phone_original }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <div class="flex flex-col">
                                                <span class="text-xs">{{ report.provider || '-' }}</span>
                                                <span class="text-xs text-gray-500">
                                                    {{ report.whatsappNumber?.description || formatWhatsAppNumber(report.whatsappNumber) }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ report.template?.name || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ report.total_boletos }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span v-if="report.status === 'success'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                Sucesso
                                            </span>
                                            <span v-else-if="report.status === 'skipped'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200" :title="report.error_message">
                                                Ignorado
                                            </span>
                                            <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200" :title="report.error_message">
                                                Erro
                                            </span>
                                            <div v-if="report.status !== 'success'" class="text-xs text-red-500 mt-1 max-w-[150px] truncate" :title="report.error_message">
                                                {{ report.error_message }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a :href="getDownloadUrl(report)" target="_blank" class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300">
                                                Baixar XLSX
                                            </a>
                                            <span class="mx-2 text-gray-300">|</span>
                                            <a :href="getClientDownloadUrl(report)" target="_blank" class="text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300">
                                                Baixar Relatório Cliente
                                            </a>
                                        </td>
                                    </tr>
                                    <tr v-if="!reports.data || reports.data.length === 0">
                                        <td colspan="8" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">
                                            Nenhum registro encontrado.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="mt-4 flex justify-between items-center" v-if="reports.links.length > 3">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <Link v-if="reports.prev_page_url" :href="reports.prev_page_url" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Anterior
                                </Link>
                                <Link v-if="reports.next_page_url" :href="reports.next_page_url" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Próximo
                                </Link>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        Mostrando <span class="font-medium">{{ reports.from }}</span> a <span class="font-medium">{{ reports.to }}</span> de <span class="font-medium">{{ reports.total }}</span> resultados
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                        <template v-for="(link, key) in reports.links" :key="key">
                                            <Link v-if="link.url" :href="link.url" 
                                                class="relative inline-flex items-center px-4 py-2 border text-sm font-medium"
                                                :class="{
                                                    'z-10 bg-primary-50 border-primary-500 text-primary-600': link.active,
                                                    'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': !link.active
                                                }"
                                                v-html="link.label"
                                            />
                                            <span v-else v-html="link.label" class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700 dark:text-gray-300"></span>
                                        </template>
                                    </nav>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
