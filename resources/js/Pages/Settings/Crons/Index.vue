<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    crons: {
        type: Array,
        required: true
    },
    can: {
        type: Object,
        default: () => ({ create: false, update: false, delete: false })
    }
});

const getTypeLabel = (type) => {
    const types = {
        'billing': 'Cobrança (Atraso)',
        'due_date': 'Aviso de Vencimento',
        'boleto': 'Emissão de Boleto',
        'birthday': 'Aniversariantes'
    };
    return types[type] || type;
};

const getPeriodLabel = (cron) => {
    if (cron.type === 'birthday') return '-';
    
    // Logic based on type to show relevant info
    if (cron.type === 'billing') {
        return `> ${cron.days_after_due || 0} dias de atraso`;
    }
    if (cron.type === 'due_date') {
        return `${cron.days_before_due || 0} dias antes`;
    }

    if (!cron.period_value) return '-';
    
    const units = {
        'days': 'dias',
        'months': 'meses',
        'years': 'anos'
    };
    return `Últimos ${cron.period_value} ${units[cron.period_unit] || ''}`;
};

const toggleStatus = (cron) => {
    router.put(route('settings.crons.update', cron.id), {
        ...cron,
        is_active: !cron.is_active
    }, {
        preserveScroll: true,
        onSuccess: () => {
            // Toast notification ideally
        }
    });
};

const deleteCron = (cron) => {
    if (confirm('Tem certeza que deseja excluir esta automação?')) {
        router.delete(route('settings.crons.destroy', cron.id));
    }
};

const runCron = (cron) => {
    if (confirm(`Deseja disparar manualmente a automação "${cron.name}"? Isso enviará mensagens para os clientes que se encaixam na regra agora.`)) {
        router.post(route('settings.crons.run', cron.id), {}, {
            preserveScroll: true
        });
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return format(new Date(dateString), 'dd/MM/yyyy HH:mm', { locale: ptBR });
};

const page = usePage();
const cronReport = computed(() => {
    // Tenta obter o report da flash session ou diretamente das props
    const v = page.props.flash?.cron_report || page.props.cron_report;
    return typeof v === 'function' ? v() : v || null;
});

const cronEnabled = ref(true);
const loadingCron = ref(false);
const updateCronStatus = async () => {
    loadingCron.value = true;
    try {
        await axios.post(route('settings.cron.contaazul.toggle'), { enabled: cronEnabled.value });
    } catch (e) {
        cronEnabled.value = !cronEnabled.value;
        alert('Erro ao atualizar status da cron.');
    } finally {
        loadingCron.value = false;
    }
};

const runSystemCommand = async (command) => {
    if (!confirm('Deseja executar este comando manualmente?')) return;
    
    try {
        const { data } = await axios.post(route('settings.cron.run-command'), { command });
        if (data.success) {
            alert('Comando executado com sucesso!\n\nSaída:\n' + data.output);
        } else {
            alert('Erro ao executar comando: ' + data.error);
        }
    } catch (e) {
        alert('Erro na requisição: ' + (e.response?.data?.error || e.message));
    }
};

onMounted(async () => {
    try {
        const { data } = await axios.get(route('settings.cron.contaazul.status'));
        cronEnabled.value = !!data.enabled;
    } catch (e) {
        cronEnabled.value = true;
    }
});
</script>

<template>
    <Head title="Crons - Automações" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Automações (Crons)
                </h2>
                <Link :href="route('settings.crons.create')">
                    <PrimaryButton>
                        Nova Automação
                    </PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-screen-2xl mx-auto sm:px-6 lg:px-8">
                
                <!-- Section: User Crons -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-8">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div v-if="cronReport" class="mb-4 text-sm">
                            <details class="bg-gray-50 dark:bg-gray-700/50 rounded border border-gray-200 dark:border-gray-600">
                                <summary class="cursor-pointer px-4 py-2 font-medium">
                                    Detalhar última execução ({{ (cronReport.stats?.sent ?? 0) }} enviadas, {{ (cronReport.stats?.errors ?? 0) }} erros, {{ (cronReport.stats?.skipped ?? 0) }} ignoradas)
                                </summary>
                                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-600">
                                    <table class="min-w-full text-xs">
                                        <thead>
                                            <tr class="text-left text-gray-500 dark:text-gray-300">
                                                <th class="pr-4 py-1">Cliente</th>
                                                <th class="pr-4 py-1">Telefone</th>
                                                <th class="pr-4 py-1">Status</th>
                                                <th class="pr-4 py-1">Mensagem</th>
                                                <th class="pr-4 py-1">Data/Hora</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(log, idx) in cronReport.logs" :key="idx" class="border-t border-gray-100 dark:border-gray-700">
                                                <td class="pr-4 py-1">{{ log.client_name || 'Desconhecido' }}</td>
                                                <td class="pr-4 py-1">{{ log.phone }}</td>
                                                <td class="pr-4 py-1">
                                                    <span v-if="log.status === 'success'" class="px-2 inline-flex text-[10px] leading-4 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                        Sucesso
                                                    </span>
                                                    <span v-else-if="log.status === 'error'" class="px-2 inline-flex text-[10px] leading-4 font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                                        Erro
                                                    </span>
                                                    <span v-else class="px-2 inline-flex text-[10px] leading-4 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                                        Ignorado
                                                    </span>
                                                </td>
                                                <td class="pr-4 py-1 text-gray-600 dark:text-gray-300">
                                                    {{ log.error_message || '-' }}
                                                </td>
                                                <td class="pr-4 py-1 text-gray-600 dark:text-gray-300">
                                                    {{ log.sent_at || '-' }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </div>

                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-medium">Suas Automações</h3>
                        </div>

                        <div v-if="crons.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            Nenhuma automação cadastrada.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nome</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tipo</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Template</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">connection_id</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Horário</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Regra/Período</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Última Execução</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                        <th scope="col" class="relative px-6 py-3">
                                            <span class="sr-only">Ações</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="cron in crons" :key="cron.id">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ cron.name }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                {{ getTypeLabel(cron.type) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ cron.message_template?.name || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ cron.connection_id ?? '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ cron.send_time }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ getPeriodLabel(cron) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ formatDate(cron.last_run_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <button @click="toggleStatus(cron)" 
                                                :disabled="!can.update"
                                                :class="[
                                                    cron.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                                    !can.update ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:opacity-80 transition-opacity'
                                                ]"
                                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                                {{ cron.is_active ? 'Ativo' : 'Inativo' }}
                                            </button>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button v-if="can.update" @click="runCron(cron)" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3">Disparar</button>
                                            <Link v-if="can.update" :href="route('settings.crons.edit', cron.id)" class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300 mr-3">Editar</Link>
                                            <button v-if="can.delete" @click="deleteCron(cron)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Excluir</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Section: System Crons -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-medium">Automações do Sistema (Multi-Empresa)</h3>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="cronEnabled" @change="updateCronStatus" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                                <span class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ cronEnabled ? 'Habilitado' : 'Desabilitado' }}</span>
                            </label>
                        </div>

                        <!-- System Commands Section -->
                        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
                            <h4 class="text-md font-semibold mb-3 text-gray-800 dark:text-gray-200">Execução Manual de Tarefas do Sistema</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="p-3 bg-white dark:bg-gray-800 rounded shadow-sm border border-gray-100 dark:border-gray-700">
                                    <div class="font-medium text-sm mb-1">Cálculo de Envios Futuros</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-3 h-8">
                                        Atualiza a lista de "Envios Futuros" com base nas regras atuais. (message:calculate-future)
                                    </div>
                                    <button @click="runSystemCommand('message:calculate-future')" class="w-full px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50 rounded text-xs font-semibold transition-colors">
                                        Executar Agora
                                    </button>
                                </div>
                                <div class="p-3 bg-white dark:bg-gray-800 rounded shadow-sm border border-gray-100 dark:border-gray-700">
                                    <div class="font-medium text-sm mb-1">Sincronizar Dados Obsoletos</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-3 h-8">
                                        Sincroniza dados de empresas que não foram atualizadas nas últimas 24h. (contaazul:sync-stale)
                                    </div>
                                    <button @click="runSystemCommand('contaazul:sync-stale')" class="w-full px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50 rounded text-xs font-semibold transition-colors">
                                        Executar Agora
                                    </button>
                                </div>
                                <div class="p-3 bg-white dark:bg-gray-800 rounded shadow-sm border border-gray-100 dark:border-gray-700">
                                    <div class="font-medium text-sm mb-1">Renovar Tokens</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-3 h-8">
                                        Força a renovação de tokens de acesso próximos da expiração. (contaazul:refresh-tokens)
                                    </div>
                                    <button @click="runSystemCommand('contaazul:refresh-tokens')" class="w-full px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50 rounded text-xs font-semibold transition-colors">
                                        Executar Agora
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
