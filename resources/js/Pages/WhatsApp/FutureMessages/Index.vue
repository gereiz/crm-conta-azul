<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Pagination from '@/Components/Pagination.vue';
import axios from 'axios';

const props = defineProps({
    schedules: Object,
    connections: Array,
    filters: Object,
});

const form = ref({
    connection_id: props.filters.connection_id || '',
    type: props.filters.type || '',
    date: props.filters.date || '',
});

const filter = () => {
    router.get(route('whatsapp.future.index'), form.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    form.value = {
        connection_id: '',
        type: '',
        date: '',
    };
    filter();
};

const recalculating = ref(false);
const recalculateFuture = async () => {
    recalculating.value = true;
    try {
        const payload = { command: 'message:calculate-future' };
        if (form.value.connection_id) {
            payload.connection_id = form.value.connection_id;
        }
        const { data } = await axios.post(route('cron.run-command'), payload);
        if (data.success) {
            alert('Cálculo de Envios Futuros executado com sucesso.');
            router.reload({ only: ['schedules'] });
        } else {
            alert('Erro ao executar: ' + (data.error || 'Falha desconhecida'));
        }
    } catch (e) {
        alert('Erro na requisição: ' + (e.response?.data?.error || e.message));
    } finally {
        recalculating.value = false;
    }
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('pt-BR');
};

const typeLabel = (item) => {
    // Se for tipo boleto ou due_date ou billing (cobranca), aplicamos a lógica visual baseada no link
    // "Billing" também pode ser uma fatura individual na visualização, se tiver invoice associado.
    if (['boleto', 'due_date', 'billing'].includes(item.message_type)) {
        // Se tem invoice e tem link -> Emissão
        if (item.invoice && item.invoice.link_boleto) {
            return 'Emissão';
        }
        // Se tem invoice e NÃO tem link -> Vencimento
        if (item.invoice && !item.invoice.link_boleto) {
            return 'Vencimento';
        }
    }

    const map = {
        'billing': 'Cobrança',
        'boleto': 'Emissão',
        'due_date': 'Vencimento',
        'birthday': 'Aniversário',
    };
    return map[item.message_type] || item.message_type;
};

const formatCurrency = (value) => {
    if (!value) return '-';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
};

const statusLabel = (status) => {
    const map = {
        'pending': 'A enviar',
        'blocked': 'Bloqueado',
        'sent': 'Enviado',
        'ignored': 'Ignorado',
    };
    return map[status] || status;
};

const statusClass = (status) => {
    const map = {
        'pending': 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        'blocked': 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
        'sent': 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        'ignored': 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    };
    return map[status] || 'bg-gray-100 text-gray-800';
};

</script>

<template>
    <Head title="Envios Futuros" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Envios Futuros
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                
                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                        <div class="md:col-span-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Empresa</label>
                            <select v-model="form.connection_id" class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">Todas</option>
                                <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.empresa_nome }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo</label>
                            <select v-model="form.type" class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">Todos</option>
                                <option value="billing">Cobrança</option>
                                <option value="boleto">Emissão</option>
                                <option value="due_date">Vencimento</option>
                                <option value="birthday">Aniversário</option>
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Data Prevista</label>
                            <input type="date" v-model="form.date" class="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        </div>
                        <div class="md:col-span-2 flex gap-2">
                            <PrimaryButton @click="filter" class="w-full justify-center">Filtrar</PrimaryButton>
                            <button @click="clearFilters" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Limpar</button>
                            <button @click="recalculateFuture" :disabled="recalculating" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 dark:bg-blue-500 dark:hover:bg-blue-600">
                                Recalcular Envios Futuros
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Data Prevista</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Empresa</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Cliente</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tipo</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ref. (Evento)</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="item in schedules.data" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ formatDate(item.scheduled_send_date) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ item.connection?.empresa_nome || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ item.cliente?.name || item.cliente?.company_name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ typeLabel(item) }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        <div v-if="item.event_date">
                                            Data: {{ formatDate(item.event_date) }}
                                        </div>
                                        <div v-if="item.invoice" class="text-xs text-gray-500 mt-1 space-y-0.5">
                                            <div class="font-medium text-gray-700 dark:text-gray-300">{{ item.invoice.descricao || 'Fatura' }}</div>
                                            <div>Valor: {{ formatCurrency(item.invoice.valor_original || item.invoice.saldo_devedor) }}</div>
                                            <div v-if="item.invoice.link_boleto">
                                                <a :href="item.invoice.link_boleto" target="_blank" class="text-blue-600 hover:text-blue-800 underline">Ver Boleto</a>
                                            </div>
                                            <div v-else class="text-gray-400 italic">
                                                (Sem link de boleto)
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="statusClass(item.status)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ statusLabel(item.status) }}
                                        </span>
                                        <div v-if="item.block_reason" class="text-xs text-red-500 mt-1 max-w-xs truncate" :title="item.block_reason">
                                            {{ item.block_reason }}
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="schedules.data.length === 0">
                                    <td colspan="6" class="px-6 py-10 text-center text-gray-500 dark:text-gray-400">
                                        Nenhum envio futuro encontrado com os filtros selecionados.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                        <Pagination :links="schedules.links" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
