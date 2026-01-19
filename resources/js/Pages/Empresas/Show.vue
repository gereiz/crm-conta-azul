<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    connection: Object,
    messageTypes: Array,
    messageSettings: Object,
    cronRules: Object,
});

const toggles = ref({ ...(props.messageSettings || {}) });

const rules = ref(Object.fromEntries((props.messageTypes || []).map(t => {
    const r = props.cronRules?.[t.key] || null;
    return [t.key, {
        rule_type: r?.rule_type || 'monthly_day',
        day_of_month: r?.day_of_month || null,
        day_of_week: r?.day_of_week ?? null,
        interval_days: r?.interval_days || null,
        exclude_weekends: !!(r?.exclude_weekends),
        is_active: r?.is_active ?? true,
    }];
})));

const savingRule = ref(false);
const selectedType = ref('');
const showRuleModal = ref(false);
const weekdays = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

const currentRule = computed(() => selectedType.value ? rules.value[selectedType.value] : null);

const ruleSummary = (typeKey) => {
    const r = rules.value[typeKey];
    if (!r) return '-';
    if (r.rule_type === 'monthly_day') {
        return `Dia do mês ${r.day_of_month ?? '-'}`;
    }
    if (r.rule_type === 'weekly_day') {
        const label = r.day_of_week !== null ? weekdays[Number(r.day_of_week)] : '-';
        return `Dia da semana ${label}`;
    }
    if (r.rule_type === 'interval_days') {
        return `Intervalo ${r.interval_days ?? '-'} dias`;
    }
    return '-';
};

const openRuleModal = (typeKey) => {
    selectedType.value = typeKey;
    showRuleModal.value = true;
};

const closeRuleModal = () => {
    showRuleModal.value = false;
    selectedType.value = '';
};

const toggleType = async (type) => {
    const enabled = !toggles.value[type];
    try {
        await axios.post(route('empresas.settings.messages', props.connection.id), {
            type,
            enabled,
        });
        toggles.value[type] = enabled;
        alert('Configuração atualizada.');
    } catch (e) {
        alert('Erro ao atualizar configuração.');
    }
};

const saveRule = async (type) => {
    savingRule.value = true;
    try {
        await axios.post(route('empresas.settings.cron', props.connection.id), {
            message_type: type,
            ...rules.value[type],
        });
        alert('Regra salva com sucesso.');
    } catch (e) {
        alert('Erro ao salvar regra.');
    } finally {
        savingRule.value = false;
    }
};
</script>

<template>
    <Head :title="`Empresa: ${connection.empresa_nome}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Detalhes da Empresa
                </h2>
                <Link :href="route('empresas.index')" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300">
                    &larr; Voltar
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Dados da Empresa -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-medium">{{ connection.empresa_nome }}</h3>
                                <p class="text-sm text-gray-500">Developer: {{ connection.email_desenvolvedor || '—' }}</p>
                            </div>
                            <a :href="route('contaazul.connections.connect', { connection: connection.id })" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-semibold hover:underline">
                                Reconectar Conta Azul
                            </a>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded">
                                <p><strong>Client ID:</strong> {{ connection.ca_client_id }}</p>
                                <p class="truncate"><strong>Redirect URI:</strong> {{ connection.ca_redirect_uri }}</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded">
                                <p><strong>Status:</strong>
                                    <span :class="connection.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                        {{ connection.is_active ? 'Ativa' : 'Inativa' }}
                                    </span>
                                </p>
                                <p><strong>Última sincronização:</strong> {{ connection.last_sync_at ? new Date(connection.last_sync_at).toLocaleString('pt-BR') : '—' }}</p>
                                <p><strong>Token expira:</strong> {{ connection.token_expires_at ? new Date(connection.token_expires_at).toLocaleString('pt-BR') : '—' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tipos de Mensagens (WhatsApp) -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-medium mb-4">Tipos de Mensagens (WhatsApp)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="t in messageTypes" :key="t.key" class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 p-4 rounded">
                                <div>
                                    <div class="font-medium">{{ t.label }}</div>
                                    <div class="text-xs text-gray-500">Chave: {{ t.key }}</div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" :checked="!!toggles[t.key]" @change="toggleType(t.key)" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                                    <span class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ toggles[t.key] ? 'Ativo' : 'Inativo' }}</span>
                                </label>
                            </div>
                        </div>
                        <div class="mt-6 border-t pt-4">
                            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 p-4 rounded">
                                <div>
                                    <div class="font-medium">Ignorar já enviado hoje</div>
                                    <div class="text-xs text-gray-500">Quando ativo, envia mesmo que já tenha enviado hoje</div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" :checked="!!toggles['ignore_sent_today']" @change="toggleType('ignore_sent_today')" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                                    <span class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ toggles['ignore_sent_today'] ? 'Ativo' : 'Inativo' }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Regras de Envio Automático (Cron) -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-medium mb-4">Regras de Envio Automático (Cron)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo de Mensagem</label>
                                <select v-model="selectedType" @change="openRuleModal(selectedType)" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                                    <option value="" disabled>Selecione um tipo</option>
                                    <option v-for="t in messageTypes" :key="t.key" :value="t.key">{{ t.label }}</option>
                                </select>
                                <div class="mt-2 text-xs text-gray-500">Selecione um tipo para configurar a regra em um modal.</div>
                            </div>
                        </div>
                        <div class="mt-6 space-y-2">
                            <div v-for="t in messageTypes" :key="t.key" class="flex items-center justify-between px-4 py-2 border border-gray-200 dark:border-gray-700 rounded">
                                <div class="text-sm">
                                    <span class="font-medium">{{ t.label }}</span>
                                    <span class="text-gray-500"> — {{ ruleSummary(t.key) }}</span>
                                </div>
                                <PrimaryButton as="button" @click="openRuleModal(t.key)">Editar</PrimaryButton>
                            </div>
                        </div>
                        <Modal :show="showRuleModal" @close="closeRuleModal">
                            <div class="p-6">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    Configurar Regra: {{ messageTypes.find(mt => mt.key === selectedType)?.label || '' }}
                                </h2>
                                <div v-if="currentRule" class="mt-4 space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo de Regra</label>
                                            <select v-model="currentRule.rule_type" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                                                <option value="monthly_day">Dia do mês</option>
                                                <option value="weekly_day">Dia da semana</option>
                                                <option value="interval_days">Intervalo em dias</option>
                                            </select>
                                        </div>
                                        <div v-if="currentRule.rule_type === 'monthly_day'">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dia do mês</label>
                                            <input type="number" v-model.number="currentRule.day_of_month" min="1" max="31" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm" />
                                        </div>
                                        <div v-if="currentRule.rule_type === 'weekly_day'">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dia da semana</label>
                                            <select v-model.number="currentRule.day_of_week" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                                                <option :value="0">Domingo</option>
                                                <option :value="1">Segunda</option>
                                                <option :value="2">Terça</option>
                                                <option :value="3">Quarta</option>
                                                <option :value="4">Quinta</option>
                                                <option :value="5">Sexta</option>
                                                <option :value="6">Sábado</option>
                                            </select>
                                        </div>
                                        <div v-if="currentRule.rule_type === 'interval_days'">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Intervalo (dias)</label>
                                            <input type="number" v-model.number="currentRule.interval_days" min="1" max="365" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm" />
                                        </div>
                                    </div>
                                    <div class="mt-2 flex items-center gap-4">
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" v-model="currentRule.exclude_weekends" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:bg-gray-900 dark:border-gray-700" />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Ignorar fins de semana</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" v-model="currentRule.is_active" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:bg-gray-900 dark:border-gray-700" />
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Regra ativa</span>
                                        </label>
                                    </div>
                                    <div class="mt-6 flex justify-end gap-3">
                                        <SecondaryButton @click="closeRuleModal">Fechar</SecondaryButton>
                                        <PrimaryButton @click="saveRule(selectedType)">Salvar</PrimaryButton>
                                    </div>
                                </div>
                            </div>
                        </Modal>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
