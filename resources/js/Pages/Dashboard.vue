<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, onMounted, watch } from 'vue';
import axios from 'axios';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    stats: Object,
    chartData: Object, // Recebe os dados do gráfico
    flash: Object,
    shouldSync: Boolean,
    connections: Array,
    selectedConnectionId: Number,
});

const page = usePage();
const flash = computed(() => props.flash || page.props.flash || {});
const stats = computed(() => props.stats || page.props.stats || {});
const chartData = computed(() => props.chartData || page.props.chartData || { labels: [], datasets: [] }); // Computado para garantir acesso
const isAdmin = computed(() => (page.props.auth?.user?.role ?? '') === 'admin');

const whapiStatus = ref(null);
const checkingWhapi = ref(false);
const syncingFinancials = ref(false);

// Use local state for stats to allow dynamic updates
const localStats = ref({
    conta_azul_connected: false,
    whatsapp_numbers_active: 0,
    messages_sent: 0,
    users_total: 0,
    cobrancas_overdue_count: 0,
    cobrancas_overdue_value: 0,
    ...(page.props.stats || {})
});
const selectedConnectionId = ref(props.selectedConnectionId || (props.connections?.[0]?.id ?? null));
const selectedConnection = computed(() => {
    return props.connections?.find(c => c.id === selectedConnectionId.value) || null;
});
const syncNotice = ref({ visible: false, type: 'info', message: '' });
const syncNoticeClass = computed(() => {
    if (!syncNotice.value.visible) return '';
    if (syncNotice.value.type === 'success') return 'bg-green-50 border border-green-200 text-green-700';
    if (syncNotice.value.type === 'error') return 'bg-red-50 border border-red-200 text-red-700';
    return 'bg-blue-50 border border-blue-200 text-blue-700';
});

const whapiStatusClass = computed(() => {
    if (whapiStatus.value === 'Operacional') return 'bg-green-500';
    if (whapiStatus.value === 'Fora de Operação') return 'bg-red-500';
    if (checkingWhapi.value) return 'bg-yellow-500 animate-pulse';
    return 'bg-gray-300';
});

const whapiBadgeClass = computed(() => {
    if (whapiStatus.value === 'Operacional') return 'text-green-600 bg-green-100 dark:bg-green-900 dark:text-green-300';
    if (whapiStatus.value === 'Fora de Operação') return 'text-red-600 bg-red-100 dark:bg-red-900 dark:text-red-300';
    if (checkingWhapi.value) return 'text-yellow-600 bg-yellow-100 dark:bg-yellow-900 dark:text-yellow-300';
    return 'text-gray-600 bg-gray-100 dark:bg-gray-700 dark:text-gray-300';
});

const checkWhapiStatus = async () => {
    checkingWhapi.value = true;
    whapiStatus.value = null;
    try {
        const response = await axios.get(route('dashboard.check-whapi'));
        if (response.data.status === 'operational') {
            whapiStatus.value = 'Operacional';
        } else {
            whapiStatus.value = 'Fora de Operação';
        }
    } catch (error) {
        console.error('Erro ao verificar status Whapi:', error);
        whapiStatus.value = 'Fora de Operação';
    } finally {
        checkingWhapi.value = false;
    }
};

const syncFinancials = async () => {
    syncingFinancials.value = true;
    syncNotice.value = {
        visible: true,
        type: 'info',
        message: `Sincronizando dados de cobranças${selectedConnection.value ? ` para ${selectedConnection.value.empresa_nome}` : ''}...`
    };
    try {
        // Add timestamp to prevent caching
        const response = await axios.get(route('dashboard.sync-financials', { _t: new Date().getTime(), connection_id: selectedConnectionId.value }));
        
        if (response.data.success && response.data.stats) {
            console.log('Sync stats received:', response.data.stats);
            
            // Force update with object spread to ensure reactivity
            localStats.value = {
                ...localStats.value,
                cobrancas_overdue_count: response.data.stats.cobrancas_overdue_count,
                cobrancas_overdue_value: response.data.stats.cobrancas_overdue_value
            };
            syncNotice.value = {
                visible: true,
                type: 'success',
                message: `Cobranças atualizadas${selectedConnection.value ? ` para ${selectedConnection.value.empresa_nome}` : ''}.`
            };
        }
    } catch (error) {
        console.error('Erro ao sincronizar financeiro:', error);
        // Show error to user if sync fails
        localStats.value = {
             ...localStats.value,
             error: 'Erro na sincronização. Tente novamente.'
        };
        syncNotice.value = {
            visible: true,
            type: 'error',
            message: 'Erro ao sincronizar cobranças. Tente novamente.'
        };
    } finally {
        syncingFinancials.value = false;
        setTimeout(() => { syncNotice.value.visible = false; }, 3000);
    }
};

const chartPeriod = ref(7);
const loadingChart = ref(false);
const localChartData = ref(props.chartData || { labels: [], datasets: [] });
const chartViewMode = ref('stacked');
const loadingNumberTypeChart = ref(false);
const numberTypeChartData = ref({ labels: [], datasets: [] });

// Se os dados vierem do backend via props, inicializa localChartData
watch(() => props.chartData, (newVal) => {
    if (newVal) localChartData.value = newVal;
}, { immediate: true });

const totalsByDay = computed(() => {
    const labels = localChartData.value?.labels || [];
    const datasets = localChartData.value?.datasets || [];
    return labels.map((_, i) => datasets.reduce((sum, ds) => sum + (Number(ds?.data?.[i] || 0)), 0));
});
const maxTotal = computed(() => {
    const t = totalsByDay.value;
    return t.length ? Math.max(...t, 1) : 1;
});
const breakdownByDay = computed(() => {
    const labels = localChartData.value?.labels || [];
    const datasets = localChartData.value?.datasets || [];
    return labels.map((_, i) => {
        return datasets.map(ds => ({
            label: ds.label,
            value: Number(ds?.data?.[i] || 0),
            color: ds.backgroundColor
        })).filter(x => x.value > 0).sort((a, b) => b.value - a.value);
    });
});
const getSegmentHeight = (index, dataset) => {
    const v = Number(dataset?.data?.[index] || 0);
    if (v <= 0) return '0%';
    const pct = (v / maxTotal.value) * 100;
    const minPct = 3;
    return `${Math.max(pct, minPct)}%`;
};
const getTotal = (index) => totalsByDay.value?.[index] || 0;
const usedDatasets = computed(() => {
    if (chartViewMode.value === 'total') {
        return [{
            label: 'Total',
            data: totalsByDay.value,
            backgroundColor: '#3B82F6',
        }];
    }
    return localChartData.value?.datasets || [];
});

const fetchChartData = async () => {
    loadingChart.value = true;
    try {
        const response = await axios.get(route('dashboard.chart-data', { period: chartPeriod.value }));
        if (response.data.success) {
            localChartData.value = response.data.chartData;
        }
    } catch (error) {
        console.error('Erro ao buscar dados do gráfico:', error);
    } finally {
        loadingChart.value = false;
    }
};

const fetchNumberTypeChart = async () => {
    loadingNumberTypeChart.value = true;
    try {
        const response = await axios.get(route('dashboard.chart-data-whatsapp', { period: chartPeriod.value }));
        if (response.data.success) {
            numberTypeChartData.value = response.data.chartData;
        }
    } catch (error) {
        console.error('Erro ao buscar dados do gráfico por número/tipo:', error);
    } finally {
        loadingNumberTypeChart.value = false;
    }
};

watch(chartPeriod, () => {
    fetchChartData();
    fetchNumberTypeChart();
});

const fetchStats = async () => {
    try {
        const response = await axios.get(route('dashboard.stats', { connection_id: selectedConnectionId.value }));
        if (response.data.success && response.data.stats) {
            localStats.value = {
                ...localStats.value,
                conta_azul_connected: response.data.stats.conta_azul_connected,
                cobrancas_overdue_count: response.data.stats.cobrancas_overdue_count,
                cobrancas_overdue_value: response.data.stats.cobrancas_overdue_value
            };
        }
    } catch (error) {
        console.error('Erro ao buscar estatísticas:', error);
    }
};
onMounted(() => {
    checkWhapiStatus();
    // Não sincronizar automaticamente; dados serão atualizados via cron e leitura do banco
    fetchStats();
    fetchNumberTypeChart();
});

watch(selectedConnectionId, async (newVal) => {
    try {
        await axios.post(route('dashboard.select-connection'), { connection_id: newVal });
    } catch (e) {
    } finally {
        await fetchStats();
    }
});
const donutNumbers = computed(() => {
    const datasets = numberTypeChartData.value?.datasets || [];
    const map = new Map();
    for (const ds of datasets) {
        const label = ds.label || '';
        const parts = label.split(' | ');
        const numberLabel = parts[0] || 'Número';
        const typeLabel = parts[1] || 'default';
        const total = (ds.data || []).reduce((sum, v) => sum + Number(v || 0), 0);
        const color = ds.backgroundColor || '#6366F1';
        if (!map.has(numberLabel)) {
            map.set(numberLabel, { label: numberLabel, total: 0, segments: [] });
        }
        const obj = map.get(numberLabel);
        obj.total += total;
        const existing = obj.segments.find(s => s.label === typeLabel);
        if (existing) {
            existing.value += total;
        } else {
            obj.segments.push({ label: typeLabel, value: total, color });
        }
    }
    const result = Array.from(map.values()).map(n => {
        const total = n.total || 0;
        let acc = 0;
        const parts = n.segments
            .filter(s => s.value > 0)
            .map(s => {
                const pct = total > 0 ? (s.value / total) * 100 : 0;
                const start = acc;
                const end = acc + pct;
                acc = end;
                return `${s.color} ${start}% ${end}%`;
            });
        const gradient = parts.length > 0 ? `conic-gradient(${parts.join(', ')})` : 'conic-gradient(#e5e7eb 0% 100%)';
        return { ...n, gradient };
    });
    return result;
});
const donutTotal = computed(() => {
    const datasets = numberTypeChartData.value?.datasets || [];
    const typeMap = new Map();
    for (const ds of datasets) {
        const label = ds.label || '';
        const parts = label.split(' | ');
        const typeLabel = parts[1] || 'default';
        const total = (ds.data || []).reduce((sum, v) => sum + Number(v || 0), 0);
        const color = ds.backgroundColor || '#6366F1';
        if (!typeMap.has(typeLabel)) {
            typeMap.set(typeLabel, { label: typeLabel, value: 0, color });
        }
        typeMap.get(typeLabel).value += total;
    }
    const segments = Array.from(typeMap.values()).filter(s => s.value > 0);
    const totalValue = segments.reduce((s, seg) => s + seg.value, 0);
    let acc = 0;
    const parts = segments.map(seg => {
        const pct = totalValue > 0 ? (seg.value / totalValue) * 100 : 0;
        const start = acc;
        const end = acc + pct;
        acc = end;
        return `${seg.color} ${start}% ${end}%`;
    });
    const gradient = parts.length > 0 ? `conic-gradient(${parts.join(', ')})` : 'conic-gradient(#e5e7eb 0% 100%)';
    return { label: 'Total por Tipo', total: totalValue, segments, gradient };
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-6">
            <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 md:px-8">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Visão Geral</h1>
                
                <!-- Feedback Messages -->
                <div v-if="flash.success" class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center shadow-sm" role="alert">
                    <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>{{ flash.success }}</span>
                </div>
                <div v-if="flash.error" class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center shadow-sm" role="alert">
                    <svg class="w-5 h-5 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>{{ flash.error }}</span>
                </div>
                <div v-if="syncNotice.visible" :class="['mb-6 px-4 py-3 rounded-lg flex items-center justify-between gap-3 shadow-sm', syncNoticeClass]" role="alert">
                    <svg v-if="syncNotice.type === 'success'" class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <svg v-else-if="syncNotice.type === 'error'" class="w-5 h-5 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <svg v-else class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 18.5a6.5 6.5 0 110-13 6.5 6.5 0 010 13z"></path></svg>
                    <span class="flex-1">{{ syncNotice.message }}</span>
                    <button @click="syncNotice.visible = false" class="text-xs bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-2 py-1 rounded">
                        X
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Integration Card -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-xl border border-gray-100 dark:border-gray-700 hover:shadow-md transition-shadow duration-300">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Conta Azul</h3>
                                <div class="p-2 bg-blue-50 dark:bg-blue-900/30 rounded-lg">
                                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="text-xs text-gray-500 dark:text-gray-400">Empresa</label>
                                <select v-model="selectedConnectionId" class="text-sm mt-1 border-gray-300 rounded-md w-full">
                                    <option v-for="c in connections" :key="c.id" :value="c.id">
                                        {{ c.empresa_nome }} (ID {{ c.id }})
                                    </option>
                                </select>
                            </div>
                            
                            <div v-if="localStats.conta_azul_connected" class="flex flex-col">
                                <div class="flex items-center mb-2">
                                    <span class="flex h-3 w-3 relative mr-2">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                                    </span>
                                    <span class="text-lg font-bold text-gray-800 dark:text-gray-200">Conectado</span>
                                </div>
                                <a v-if="selectedConnectionId" :href="route('contaazul.connections.connect', { connection: selectedConnectionId })" class="text-xs text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors">
                                    Reautorizar / Atualizar Token
                                </a>
                            </div>
                            <div v-else>
                                <div class="flex items-center justify-between">
                                    <span class="text-lg font-bold text-gray-500 dark:text-gray-400">Desconectado</span>
                                    <a v-if="selectedConnectionId" :href="route('contaazul.connections.connect', { connection: selectedConnectionId })" class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300 font-semibold hover:underline">
                                        Conectar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cobranças Stats -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-xl border border-gray-100 dark:border-gray-700 hover:shadow-md transition-shadow duration-300">
                         <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cobranças</h3>
                            </div>
                            <div class="flex flex-col">
                                <div class="flex items-end justify-between mb-1">
                                    <div class="flex items-center">
                                        <p class="text-2xl font-bold text-gray-900 dark:text-white mr-2">{{ localStats.cobrancas_overdue_count }}</p>
                                    </div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 mb-1">Faturas Atrasadas</span>
                                </div>
                                <div class="text-sm font-medium text-red-600 dark:text-red-400">
                                    {{ new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(localStats.cobrancas_overdue_value) || 0) }}
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <SecondaryButton v-if="isAdmin" size="xs" @click="syncFinancials" :disabled="syncingFinancials">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v6h6M20 20v-6h-6M5 19a9 9 0 0114-14" />
                                        </svg>
                                        <span>{{ syncingFinancials ? 'Sincronizando...' : 'Sincronizar' }}</span>
                                    </SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- WhatsApp Stats -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-xl border border-gray-100 dark:border-gray-700 hover:shadow-md transition-shadow duration-300">
                         <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">WhatsApp</h3>
                                <div class="p-2 bg-green-50 dark:bg-green-900/30 rounded-lg">
                                    <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                                </div>
                            </div>
                            <div class="flex items-end justify-between">
                                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ localStats.whatsapp_numbers_active }}</p>
                                <span class="text-sm text-gray-500 dark:text-gray-400 mb-1">Ativos</span>
                            </div>
                        </div>
                    </div>

                    <!-- Users Stats -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-xl border border-gray-100 dark:border-gray-700 hover:shadow-md transition-shadow duration-300">
                         <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Usuários</h3>
                                <div class="p-2 bg-orange-50 dark:bg-orange-900/30 rounded-lg">
                                    <svg class="w-6 h-6 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                                </div>
                            </div>
                            <div class="flex items-end justify-between">
                                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ localStats.users_total }}</p>
                                <span class="text-sm text-gray-500 dark:text-gray-400 mb-1">Cadastrados</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity / Charts Placeholder -->
                <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Envios</h3>
                            <div class="flex items-center gap-2">
                                <select v-model="chartViewMode" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-500 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                                    <option value="stacked">Empresas</option>
                                    <option value="total">Total</option>
                                </select>
                                <select v-model="chartPeriod" :disabled="loadingChart" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-500 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                                    <option :value="7">Últimos 7 dias</option>
                                    <option :value="15">Últimos 15 dias</option>
                                    <option :value="30">Últimos 30 dias</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Stacked Bar Chart (CSS Pure) -->
                        <div class="h-64 flex items-end justify-between gap-2 px-2 relative overflow-visible" :class="{ 'opacity-50': loadingChart }">
                            <!-- Y-Axis Lines (Background) -->
                            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none opacity-10">
                                <div class="border-t border-gray-900 w-full"></div>
                                <div class="border-t border-gray-900 w-full"></div>
                                <div class="border-t border-gray-900 w-full"></div>
                                <div class="border-t border-gray-900 w-full"></div>
                                <div class="border-t border-gray-900 w-full"></div>
                            </div>

                            <div v-for="(label, index) in localChartData.labels" :key="index" class="flex flex-col items-center flex-1 group h-full justify-end">
                                <div class="w-full rounded-t-lg relative flex flex-col justify-end overflow-hidden transition-all duration-300 bg-gray-50 dark:bg-gray-700/30 hover:bg-gray-100 dark:hover:bg-gray-700/50" style="height: 100%;">
                                    
                                    <!-- Stacked Segments -->
                                    <template v-for="(dataset, dIndex) in usedDatasets" :key="dIndex">
                                        <div 
                                            v-if="dataset.data[index] > 0"
                                            :style="{ height: getSegmentHeight(index, dataset), backgroundColor: dataset.backgroundColor }"
                                            class="w-full transition-all duration-500 relative group/segment outline outline-1 outline-white/70 dark:outline-gray-900/40"
                                            :title="dataset.label + ': ' + dataset.data[index]"
                                        >
                                            <!-- Tooltip per segment -->
                                            <div class="opacity-0 group-hover/segment:opacity-100 absolute bottom-full left-1/2 -translate-x-1/2 mb-1 bg-gray-900 text-white text-[11px] py-1 px-2 rounded pointer-events-none whitespace-nowrap z-20 shadow">
                                                {{ dataset.label }}: {{ dataset.data[index] }}
                                            </div>
                                        </div>
                                    </template>

                                    <div class="opacity-0 group-hover:opacity-100 absolute -top-2 left-1/2 -translate-x-1/2 -translate-y-full bg-gray-900 text-white text-[11px] py-2 px-3 rounded shadow-lg pointer-events-none z-30 w-max max-w-[260px]">
                                        <div class="font-semibold text-center mb-1">Total: {{ getTotal(index) }}</div>
                                        <div v-if="chartViewMode === 'stacked'" v-for="item in breakdownByDay[index].slice(0,6)" :key="item.label" class="flex items-center justify-between gap-2">
                                            <span class="flex items-center"><span class="w-2 h-2 rounded-full mr-1" :style="{ backgroundColor: item.color }"></span>{{ item.label }}</span>
                                            <span>{{ item.value }}</span>
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[10px] text-gray-400 mt-2 font-medium rotate-45 sm:rotate-0 origin-left">{{ label }}</span>
                            </div>
                        </div>
                        
                        <!-- Legend -->
                        <div class="mt-6 flex flex-wrap gap-3 justify-center">
                            <div v-for="(dataset, i) in usedDatasets" :key="i" class="flex items-center">
                                <span class="w-3 h-3 rounded-full mr-1" :style="{ backgroundColor: dataset.backgroundColor }"></span>
                                <span class="text-xs text-gray-600 dark:text-gray-400">{{ dataset.label }}</span>
                            </div>
                            <div v-if="localChartData.datasets.length === 0" class="text-xs text-gray-400 italic">
                                Nenhum envio registrado no período.
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm lg:col-span-1">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Status do Sistema</h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-2 h-2 rounded-full mr-3" :class="whapiStatusClass"></div>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Serviço Whapi</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span v-if="whapiStatus || checkingWhapi" class="text-xs font-semibold px-2 py-1 rounded-full" :class="whapiBadgeClass">
                                        {{ checkingWhapi ? 'Verificando...' : whapiStatus }}
                                    </span>
                                    <button 
                                        @click="checkWhapiStatus" 
                                        :disabled="checkingWhapi"
                                        class="text-xs bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded transition-colors disabled:opacity-50"
                                    >
                                        {{ checkingWhapi ? '...' : 'Testar' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm lg:col-span-3">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Envios por Número e Tipo</h3>
                            <div class="flex items-center gap-2">
                                <select v-model="chartPeriod" :disabled="loadingNumberTypeChart" class="text-xs border-gray-200 dark:border-gray-600 rounded-lg text-gray-500 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                                    <option :value="7">Últimos 7 dias</option>
                                    <option :value="15">Últimos 15 dias</option>
                                    <option :value="30">Últimos 30 dias</option>
                                </select>
                            </div>
                        </div>
                        <div :class="{ 'opacity-50': loadingNumberTypeChart }">
                            <div v-if="donutNumbers.length === 0" class="text-xs text-gray-400 italic px-2">
                                Nenhum envio registrado no período.
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div v-for="n in donutNumbers" :key="n.label" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                                    <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-4">{{ n.label }}</h4>
                                    <div class="flex justify-center">
                                        <div class="relative w-48 h-48 rounded-full" :style="{ background: n.gradient }">
                                            <div class="absolute inset-6 rounded-full bg-white dark:bg-gray-800 flex items-center justify-center border border-gray-100 dark:border-gray-700">
                                                <span class="text-2xl font-bold text-gray-900 dark:text-white">{{ n.total }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-6 flex flex-wrap gap-3 justify-center">
                                        <div v-for="(s, idx) in n.segments" :key="idx" class="flex items-center">
                                            <span class="w-3 h-3 rounded-full mr-1" :style="{ backgroundColor: s.color }"></span>
                                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ s.label }}: {{ s.value }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-8 bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-6 shadow-sm">
                                <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-4">{{ donutTotal.label }}</h4>
                                <div class="flex justify-center">
                                    <div class="relative w-56 h-56 rounded-full" :style="{ background: donutTotal.gradient }">
                                        <div class="absolute inset-7 rounded-full bg-white dark:bg-gray-800 flex items-center justify-center border border-gray-100 dark:border-gray-700">
                                            <span class="text-3xl font-bold text-gray-900 dark:text-white">{{ donutTotal.total }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-6 flex flex-wrap gap-3 justify-center">
                                    <div v-for="(s, idx) in donutTotal.segments" :key="'t'+idx" class="flex items-center">
                                        <span class="w-3 h-3 rounded-full mr-1" :style="{ backgroundColor: s.color }"></span>
                                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ s.label }}: {{ s.value }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
