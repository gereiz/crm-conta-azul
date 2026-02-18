<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { watch } from 'vue';

const props = defineProps({
    cron: {
        type: Object,
        default: null
    },
    templates: {
        type: Array,
        required: true
    },
    whatsappNumbers: {
        type: Array,
        required: true
    },
    connections: {
        type: Array,
        required: false,
        default: () => []
    }
});

console.log('WhatsappNumbers:', props.whatsappNumbers);

const form = useForm({
    name: props.cron?.name || '',
    is_active: props.cron?.is_active ?? true,
    send_time: props.cron?.send_time || '09:00',
    message_template_id: props.cron?.message_template_id || '',
    whatsapp_number_id: props.cron?.whatsapp_number_id || '',
    connection_id: props.cron?.connection_id || '',
    type: props.cron?.type || '',
    
    // Execution Rules
    rule_type: props.cron?.rule_type || 'daily',
    day_of_month: props.cron?.day_of_month || [],
    day_of_week: props.cron?.day_of_week || [],
    interval_days: props.cron?.interval_days || null,
    exclude_weekends: props.cron?.exclude_weekends ?? false,

    // Type specific
    period_value: props.cron?.period_value ?? 10,
    period_unit: props.cron?.period_unit ?? 'days',
    days_before_due: props.cron?.days_before_due ?? 10,
    days_after_due: props.cron?.days_after_due ?? 1,
    limit_link_preview: props.cron?.limit_link_preview ?? false,
    disable_link_preview: props.cron?.disable_link_preview ?? false,
    run_when_delayed: props.cron?.run_when_delayed ?? false,
    send_without_boleto: props.cron?.send_without_boleto ?? false,
    no_boleto_template_id: props.cron?.no_boleto_template_id || '',
});

const submit = () => {
    if (props.cron) {
        form.put(route('settings.crons.update', props.cron.id));
    } else {
        form.post(route('settings.crons.store'));
    }
};

// Reset defaults when type changes (optional, but good UX)
watch(() => form.type, (newType) => {
    if (!props.cron) { // Only reset if creating new
        if (newType === 'billing') {
            form.days_after_due = 5;
            form.period_value = 30;
            form.period_unit = 'days';
        } else if (newType === 'due_date') {
            form.days_before_due = 3;
        } else if (newType === 'boleto') {
            form.days_before_due = 10;
        }
    }
});
</script>

<template>
    <Head :title="props.cron ? 'Editar Automação' : 'Nova Automação'" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ props.cron ? 'Editar Automação' : 'Nova Automação' }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <form @submit.prevent="submit" class="space-y-6">
                            
                            <!-- Name -->
                            <div>
                                <InputLabel for="name" value="Nome da Automação" />
                                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autofocus />
                                <InputError class="mt-2" :message="form.errors.name" />
                            </div>

                            <!-- Active Status -->
                            <div class="flex items-center">
                                <input id="is_active" type="checkbox" v-model="form.is_active" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                <label for="is_active" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300">Automação Ativa</label>
                            </div>

                            <!-- Schedule Time -->
                            <div>
                                <InputLabel for="send_time" value="Horário de Envio" />
                                <TextInput id="send_time" type="time" class="mt-1 block w-full" v-model="form.send_time" required />
                                <InputError class="mt-2" :message="form.errors.send_time" />
                            </div>

                            <!-- Message Template -->
                            <div>
                                <InputLabel for="message_template_id" value="Mensagem Padrão" />
                                <select id="message_template_id" v-model="form.message_template_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm" required>
                                    <option value="" disabled>Selecione uma mensagem</option>
                                    <option v-for="template in templates" :key="template.id" :value="template.id">
                                        {{ template.name }}
                                    </option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.message_template_id" />
                            </div>

                            <!-- WhatsApp Number -->
                            <div>
                                <InputLabel for="whatsapp_number_id" value="Número do WhatsApp" />
                                <select id="whatsapp_number_id" v-model="form.whatsapp_number_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm" required>
                                    <option value="" disabled>Selecione um número</option>
                                    <option v-for="number in whatsappNumbers" :key="number.id" :value="number.id">
                                        {{ number.description }} (+{{ number.ddi }} {{ number.ddd }} {{ number.phone }})
                                    </option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.whatsapp_number_id" />
                            </div>

                            <!-- Empresa (Conexão Conta Azul) -->
                            <div>
                                <InputLabel for="connection_id" value="Empresa (Conexão Conta Azul)" />
                                <select id="connection_id" v-model="form.connection_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm">
                                    <option value="">Todas as Empresas</option>
                                    <option v-for="c in connections" :key="c.id" :value="c.id">
                                        {{ c.empresa_nome }}
                                    </option>
                                </select>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Se selecionada, a automação processará apenas clientes e faturas da empresa escolhida.</p>
                                <InputError class="mt-2" :message="form.errors.connection_id" />
                            </div>

                            <!-- Type -->
                            <div>
                                <InputLabel for="type" value="Tipo de Automação" />
                                <select id="type" v-model="form.type" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm" required>
                                    <option value="" disabled>Selecione o tipo</option>
                                    <option value="billing">Cobrança (Faturas em atraso)</option>
                                    <option value="due_date">Aviso de Vencimento</option>
                                    <option value="boleto">Emissão de Boletos</option>
                                    <option value="birthday">Aniversariantes do Dia</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.type" />
                            </div>

                            <!-- Regras de Agendamento (Quando executar) -->
                            <div class="space-y-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">
                                <h3 class="font-medium text-blue-900 dark:text-blue-100">Regras de Agendamento (Quando executar?)</h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel for="rule_type" value="Frequência" />
                                        <select id="rule_type" v-model="form.rule_type" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm" required>
                                            <option value="daily">Todo dia (Verificar diariamente)</option>
                                            <option value="monthly_day">Dia específico do mês</option>
                                            <option value="weekly_day">Dia específico da semana</option>
                                            <option value="interval_days">Intervalo de dias</option>
                                        </select>
                                        <InputError class="mt-2" :message="form.errors.rule_type" />
                                    </div>

                                    <!-- Campos Condicionais da Regra -->
                                    <div v-if="form.rule_type === 'monthly_day'">
                                        <InputLabel value="Dias do mês (selecione um ou mais)" />
                                        <div class="mt-2 grid grid-cols-7 gap-2">
                                            <label v-for="d in 31" :key="d" class="flex flex-col items-center justify-center p-2 border rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                                                   :class="form.day_of_month.includes(d) ? 'bg-blue-100 border-blue-500 text-blue-700 dark:bg-blue-900 dark:border-blue-400 dark:text-blue-200' : 'border-gray-300 dark:border-gray-600'">
                                                <input type="checkbox" :value="d" v-model="form.day_of_month" class="hidden">
                                                <span class="text-sm font-medium">{{ d }}</span>
                                            </label>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Selecione os dias em que a automação deve rodar.</p>
                                        <InputError class="mt-2" :message="form.errors.day_of_month" />
                                    </div>
                                    
                                    <div v-if="form.rule_type === 'weekly_day'">
                                        <InputLabel value="Dias da semana (selecione um ou mais)" />
                                        <div class="mt-2 space-y-2">
                                            <div class="flex flex-wrap gap-2">
                                                <label v-for="(day, index) in ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']" :key="index"
                                                       class="inline-flex items-center px-3 py-1.5 border rounded-full cursor-pointer transition-colors"
                                                       :class="form.day_of_week.includes(index) ? 'bg-blue-100 border-blue-500 text-blue-700 dark:bg-blue-900 dark:border-blue-400 dark:text-blue-200' : 'bg-white border-gray-300 text-gray-700 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'">
                                                    <input type="checkbox" :value="index" v-model="form.day_of_week" class="hidden">
                                                    <span class="text-sm">{{ day }}</span>
                                                </label>
                                            </div>
                                        </div>
                                        <InputError class="mt-2" :message="form.errors.day_of_week" />
                                    </div>

                                    <div v-if="form.rule_type === 'interval_days'">
                                        <InputLabel for="interval_days" value="A cada X dias" />
                                        <TextInput id="interval_days" type="number" class="mt-1 block w-full" v-model="form.interval_days" min="1" />
                                        <InputError class="mt-2" :message="form.errors.interval_days" />
                                    </div>
                                </div>

                                <!-- Exclude Weekends -->
                                <div class="flex items-center">
                                    <input id="exclude_weekends" type="checkbox" v-model="form.exclude_weekends" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                    <label for="exclude_weekends" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300">Não enviar em finais de semana (Sábado/Domingo)</label>
                                </div>
                                <p class="text-xs text-blue-600 dark:text-blue-400">
                                    <span v-if="form.rule_type === 'daily'">A automação rodará todos os dias no horário configurado.</span>
                                    <span v-if="form.rule_type === 'monthly_day'">A automação rodará apenas nos dias {{ form.day_of_month.join(', ') || 'X' }} de cada mês.</span>
                                    <span v-if="form.rule_type === 'weekly_day'">A automação rodará apenas nos dias da semana selecionados.</span>
                                    <span v-if="form.rule_type === 'interval_days'">A automação rodará em ciclos de {{ form.interval_days || 'X' }} dias.</span>
                                </p>
                            </div>
                            
                            <!-- Limitar preview de links -->
                            <div class="flex items-center">
                                <input id="limit_link_preview" type="checkbox" v-model="form.limit_link_preview" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                <label for="limit_link_preview" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300">Limitar preview (somente primeiro link clicável)</label>
                            </div>
                            <!-- Remover preview de links -->
                            <div class="flex items-center">
                                <input id="disable_link_preview" type="checkbox" v-model="form.disable_link_preview" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                <label for="disable_link_preview" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300">Remover preview (links clicáveis sem esquema e instrução “copie e cole”)</label>
                            </div>
                            <!-- Rodar mesmo em atraso -->
                            <div class="flex items-center">
                                <input id="run_when_delayed" type="checkbox" v-model="form.run_when_delayed" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                <label for="run_when_delayed" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300">Rodar mesmo em atraso</label>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Quando ativo, se uma execução prevista (ex.: terça/quinta às 09:00) for perdida, será enfileirada e executada com espaçamento mínimo de 15 minutos entre automações.</p>

                            <!-- Conditional Fields -->
                            <div v-if="form.type === 'billing'" class="space-y-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">Regras de Cobrança</h3>
                                <div>
                                    <InputLabel for="days_after_due" value="Dias em atraso (maior que)" />
                                    <TextInput id="days_after_due" type="number" class="mt-1 block w-full" v-model="form.days_after_due" min="0" required />
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Ex: Enviar se atraso > 0 dias (equivale a 1 dia de atraso)</p>
                                    <InputError class="mt-2" :message="form.errors.days_after_due" />
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel for="period_value" value="Período Analisado (Valor)" />
                                        <TextInput id="period_value" type="number" class="mt-1 block w-full" v-model="form.period_value" min="1" />
                                    </div>
                                    <div>
                                        <InputLabel for="period_unit" value="Unidade" />
                                        <select id="period_unit" v-model="form.period_unit" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm">
                                            <option value="days">Dias</option>
                                            <option value="months">Meses</option>
                                            <option value="years">Anos</option>
                                        </select>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Ex: Analisar faturas dos últimos 30 dias</p>

                                <!-- Cobrança sem boleto -->
                                <div class="mt-4 space-y-2 p-3 rounded border border-yellow-200 bg-yellow-50 dark:border-yellow-700 dark:bg-yellow-900/20">
                                    <div class="flex items-center">
                                        <input id="send_without_boleto" type="checkbox" v-model="form.send_without_boleto" class="w-4 h-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500 dark:focus:ring-yellow-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                        <label for="send_without_boleto" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300">Cobrança sem boleto</label>
                                    </div>
                                    <div>
                                        <InputLabel for="no_boleto_template_id" value="Mensagem para clientes SEM link de boleto" />
                                        <select id="no_boleto_template_id" v-model="form.no_boleto_template_id" :disabled="!form.send_without_boleto" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm">
                                            <option value="">Selecione um layout (opcional)</option>
                                            <option v-for="template in templates" :key="template.id" :value="template.id">
                                                {{ template.name }}
                                            </option>
                                        </select>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Se habilitado, envia esta mensagem quando houver cobranças, mas nenhum link de boleto disponível.</p>
                                        <InputError class="mt-2" :message="form.errors.no_boleto_template_id" />
                                    </div>
                                </div>
                            </div>

                            <div v-if="form.type === 'due_date'" class="space-y-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">Regras de Vencimento</h3>
                                <div>
                                    <InputLabel for="days_before_due" value="Dias antes do vencimento" />
                                    <TextInput id="days_before_due" type="number" class="mt-1 block w-full" v-model="form.days_before_due" min="0" required />
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Ex: Enviar 3 dias antes de vencer (0 = no dia do vencimento)</p>
                                    <InputError class="mt-2" :message="form.errors.days_before_due" />
                                </div>
                            </div>

                            <div v-if="form.type === 'boleto'" class="space-y-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">Regras de Emissão</h3>
                                <div>
                                    <InputLabel for="boleto_days_before_due" value="Dias antes do vencimento" />
                                    <TextInput id="boleto_days_before_due" type="number" class="mt-1 block w-full" v-model="form.days_before_due" min="0" required />
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Ex: Enviar boletos que vencem nos próximos N dias (0 = hoje).</p>
                                    <p class="text-xs text-gray-400">Obs.: period_value permanece como fallback técnico no backend.</p>
                                    <InputError class="mt-2" :message="form.errors.days_before_due" />
                                </div>
                            </div>

                            <div class="flex items-center justify-end mt-4">
                                <Link :href="route('settings.crons.index')" class="mr-4">
                                    <SecondaryButton>
                                        Cancelar
                                    </SecondaryButton>
                                </Link>
                                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                    {{ props.cron ? 'Salvar Alterações' : 'Criar Automação' }}
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
