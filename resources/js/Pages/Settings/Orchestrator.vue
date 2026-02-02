<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import axios from 'axios';

const props = defineProps({
    settings: Object,
});

const form = useForm({
    orchestrator_delay_min_seconds: props.settings?.orchestrator_delay_min_seconds ?? 6,
    orchestrator_delay_max_seconds: props.settings?.orchestrator_delay_max_seconds ?? 20,
    orchestrator_batch_size: props.settings?.orchestrator_batch_size ?? 30,
    orchestrator_batch_interval_min_seconds: props.settings?.orchestrator_batch_interval_min_seconds ?? 300,
    orchestrator_batch_interval_max_seconds: props.settings?.orchestrator_batch_interval_max_seconds ?? 420,
    orchestrator_hourly_limit_per_number: props.settings?.orchestrator_hourly_limit_per_number ?? 100,
    orchestrator_safe_start_hour: props.settings?.orchestrator_safe_start_hour ?? '08:00',
    orchestrator_safe_end_hour: props.settings?.orchestrator_safe_end_hour ?? '20:00',
    orchestrator_concurrent_cooldown_minutes: props.settings?.orchestrator_concurrent_cooldown_minutes ?? 15,
    orchestrator_pause_on_error_minutes: props.settings?.orchestrator_pause_on_error_minutes ?? 30,
    orchestrator_warmup_day1_limit: props.settings?.orchestrator_warmup_day1_limit ?? 20,
    orchestrator_warmup_day2_limit: props.settings?.orchestrator_warmup_day2_limit ?? 40,
    orchestrator_warmup_day3_limit: props.settings?.orchestrator_warmup_day3_limit ?? 60,
});

const submit = () => {
    form.post(route('settings.orchestrator.save'), {
        preserveScroll: true,
    });
};

const statusItems = ref([]);
const loadingStatus = ref(false);
const lastUpdated = ref(null);

const fetchStatus = async () => {
    loadingStatus.value = true;
    try {
        const resp = await axios.get(route('settings.orchestrator.status'));
        statusItems.value = resp.data.items || [];
        lastUpdated.value = resp.data.now || null;
    } catch (e) {
    } finally {
        loadingStatus.value = false;
    }
};

const resumeNumber = async (id) => {
    try {
        await axios.post(route('settings.orchestrator.resume'), { whatsapp_number_id: id });
        await fetchStatus();
    } catch (e) {}
};

const forceResumeNumber = async (id) => {
    try {
        await axios.post(route('settings.orchestrator.force_resume'), { whatsapp_number_id: id });
        await fetchStatus();
    } catch (e) {}
};

const clearQueue = async (id) => {
    try {
        if (!confirm('Zerar a fila de hoje para este número?')) return;
        await axios.post(route('settings.orchestrator.clear_queue'), { whatsapp_number_id: id });
        await fetchStatus();
    } catch (e) {}
};

onMounted(() => {
    fetchStatus();
    setInterval(fetchStatus, 5000);
});
</script>

<template>
    <Head title="Orquestrador de Envio" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Orquestrador de Envio (WhatsApp)
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl p-6">
                    <div class="mb-6">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status por Número</h3>
                        <div class="mt-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Atualizado: {{ lastUpdated || '-' }}</span>
                                <SecondaryButton @click="fetchStatus" :disabled="loadingStatus">{{ loadingStatus ? '...' : 'Atualizar' }}</SecondaryButton>
                            </div>
                            <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700">
                                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Número</th>
                                            <th class="px-3 py-2 text-left">Provider</th>
                                            <th class="px-3 py-2 text-left">Instância</th>
                                            <th class="px-3 py-2 text-left">Em andamento</th>
                                            <th class="px-3 py-2 text-left">Pausado até</th>
                                            <th class="px-3 py-2 text-left">Contador hora</th>
                                            <th class="px-3 py-2 text-left">Tempo p/ reset hora</th>
                                            <th class="px-3 py-2 text-left">Contador dia</th>
                                            <th class="px-3 py-2 text-left">Fila</th>
                                            <th class="px-3 py-2 text-left">Próxima janela</th>
                                            <th class="px-3 py-2 text-left"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <tr v-for="it in statusItems" :key="it.id" class="bg-white dark:bg-gray-800">
                                            <td class="px-3 py-2">{{ it.description || ('#'+it.id) }}</td>
                                            <td class="px-3 py-2">{{ it.provider }}</td>
                                            <td class="px-3 py-2">{{ it.instance || '-' }}</td>
                                            <td class="px-3 py-2">
                                                <span :class="it.in_progress ? 'text-green-600' : 'text-gray-500'">{{ it.in_progress ? 'Sim' : 'Não' }}</span>
                                            </td>
                                            <td class="px-3 py-2">{{ it.paused_until || '-' }}</td>
                                            <td class="px-3 py-2">{{ it.hourly_count }}</td>
                                            <td class="px-3 py-2">{{ it.hourly_remaining_seconds != null ? (Math.ceil(it.hourly_remaining_seconds/60)+'min') : '-' }}</td>
                                            <td class="px-3 py-2">{{ it.daily_count }}</td>
                                            <td class="px-3 py-2">{{ it.queue_count }}</td>
                                            <td class="px-3 py-2">{{ it.next_available_at || '-' }}</td>
                                            <td class="px-3 py-2">
                                                <div class="flex gap-2">
                                                    <SecondaryButton @click="resumeNumber(it.id)" :disabled="loadingStatus">Retomar</SecondaryButton>
                                                    <SecondaryButton @click="forceResumeNumber(it.id)" :disabled="loadingStatus" class="bg-yellow-100 dark:bg-yellow-900/50 text-yellow-700 dark:text-yellow-300 hover:bg-yellow-200 dark:hover:bg-yellow-800">Retomar Agora</SecondaryButton>
                                                    <SecondaryButton @click="clearQueue(it.id)" :disabled="loadingStatus" class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-800">Zerar Fila</SecondaryButton>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Delay Mínimo (s)</label>
                                <TextInput type="number" v-model.number="form.orchestrator_delay_min_seconds" class="w-full" min="1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Delay Máximo (s)</label>
                                <TextInput type="number" v-model.number="form.orchestrator_delay_max_seconds" class="w-full" min="1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tamanho do Lote</label>
                                <TextInput type="number" v-model.number="form.orchestrator_batch_size" class="w-full" min="1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Intervalo Mín. entre Lotes (s)</label>
                                <TextInput type="number" v-model.number="form.orchestrator_batch_interval_min_seconds" class="w-full" min="60" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Intervalo Máx. entre Lotes (s)</label>
                                <TextInput type="number" v-model.number="form.orchestrator_batch_interval_max_seconds" class="w-full" min="60" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Limite Horário por Número</label>
                                <TextInput type="number" v-model.number="form.orchestrator_hourly_limit_per_number" class="w-full" min="1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Início Janela Segura (HH:mm)</label>
                                <TextInput type="text" v-model="form.orchestrator_safe_start_hour" class="w-full" placeholder="08:00" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fim Janela Segura (HH:mm)</label>
                                <TextInput type="text" v-model="form.orchestrator_safe_end_hour" class="w-full" placeholder="20:00" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cooldown Concorrência (min)</label>
                                <TextInput type="number" v-model.number="form.orchestrator_concurrent_cooldown_minutes" class="w-full" min="1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Pausa em Erros (min)</label>
                                <TextInput type="number" v-model.number="form.orchestrator_pause_on_error_minutes" class="w-full" min="1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Warm-up Dia 1</label>
                                <TextInput type="number" v-model.number="form.orchestrator_warmup_day1_limit" class="w-full" min="1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Warm-up Dia 2</label>
                                <TextInput type="number" v-model.number="form.orchestrator_warmup_day2_limit" class="w-full" min="1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Warm-up Dia 3</label>
                                <TextInput type="number" v-model.number="form.orchestrator_warmup_day3_limit" class="w-full" min="1" />
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton>Salvar Parâmetros</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
