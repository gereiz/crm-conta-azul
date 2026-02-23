<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import axios from 'axios';
import { ref, onMounted, watch } from 'vue';

const props = defineProps({
    settings: Object,
});

const form = useForm({
    system_name: props.settings?.system_name ?? '',
    primary_color: props.settings?.primary_color ?? '#6366F1',
    secondary_color: props.settings?.secondary_color ?? '#22C55E',
    logo: null,
    favicon: null,
    evolution_api_base_url: props.settings?.evolution_api_base_url ?? '',
    whapi_webhook_enabled: props.settings?.whapi_webhook_enabled ?? false,
    whapi_webhook_secret: props.settings?.whapi_webhook_secret ?? '',
    whapi_webhook_url: props.settings?.whapi_webhook_url ?? '',
    evolution_webhook_enabled: props.settings?.evolution_webhook_enabled ?? false,
    evolution_webhook_secret: props.settings?.evolution_webhook_secret ?? '',
    evolution_webhook_url: props.settings?.evolution_webhook_url ?? '',
});

const currentOrigin = typeof window !== 'undefined' && window?.location ? window.location.origin : '';
const cronEnabled = ref(null);
// Sincroniza quando props.settings muda após salvar
watch(() => props.settings, (s) => {
  if (!s) return;
  form.system_name = s.system_name ?? form.system_name;
  form.primary_color = s.primary_color ?? form.primary_color;
  form.secondary_color = s.secondary_color ?? form.secondary_color;
  form.evolution_api_base_url = s.evolution_api_base_url ?? form.evolution_api_base_url;
  form.whapi_webhook_enabled = Boolean(s.whapi_webhook_enabled ?? false);
  form.whapi_webhook_secret = s.whapi_webhook_secret ?? form.whapi_webhook_secret;
  form.whapi_webhook_url = s.whapi_webhook_url ?? form.whapi_webhook_url;
  form.evolution_webhook_enabled = Boolean(s.evolution_webhook_enabled ?? false);
  form.evolution_webhook_secret = s.evolution_webhook_secret ?? form.evolution_webhook_secret;
  form.evolution_webhook_url = s.evolution_webhook_url ?? form.evolution_webhook_url;
}, { deep: true });
const cronEnabled = ref(null);
const checkingCron = ref(false);

const checkCronStatus = async () => {
    checkingCron.value = true;
    try {
        const response = await axios.get(route('settings.cron.contaazul.status'));
        cronEnabled.value = !!response.data.enabled;
    } catch (e) {
        cronEnabled.value = null;
    } finally {
        checkingCron.value = false;
    }
};

const submit = () => {
    form.post(route('settings.system.save'), {
        forceFormData: true,
        preserveScroll: true,
    });
};

onMounted(() => {
    checkCronStatus();
});
</script>

<template>
    <Head title="Configurações do Sistema" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Configurações do Sistema
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-xl p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            Status Cron Conta Azul
                        </h3>
                        <div
                            :class="[
                                'px-2 inline-flex items-center text-xs leading-5 font-semibold rounded-full',
                                cronEnabled === true ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' :
                                cronEnabled === false ? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' :
                                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300'
                            ]"
                        >
                            <svg v-if="checkingCron" class="animate-spin w-3 h-3 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>Cron diária: {{ cronEnabled === true ? 'Ativa' : cronEnabled === false ? 'Desativada' : 'Verificando...' }}</span>
                        </div>
                    </div>
                    <form @submit.prevent="submit" class="space-y-6" enctype="multipart/form-data">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Nome do Sistema
                            </label>
                            <TextInput v-model="form.system_name" class="w-full" placeholder="Ex: IbitWeb" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Cor Primária
                                </label>
                                <div class="flex items-center gap-3">
                                    <input type="color" v-model="form.primary_color" class="h-10 w-16 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent" />
                                    <TextInput v-model="form.primary_color" class="w-full" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Cor Secundária
                                </label>
                                <div class="flex items-center gap-3">
                                    <input type="color" v-model="form.secondary_color" class="h-10 w-16 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent" />
                                    <TextInput v-model="form.secondary_color" class="w-full" />
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Logo do Sistema
                                </label>
                                <input type="file" @change="e => form.logo = e.target.files[0]" accept="image/*" class="block w-full text-sm text-gray-700 dark:text-gray-300" />
                                <div v-if="props.settings?.logo_path" class="mt-3">
                                    <img :src="`/storage/${props.settings.logo_path}`" alt="Logo" class="h-10" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Favicon
                                </label>
                                <input type="file" @change="e => form.favicon = e.target.files[0]" accept="image/*" class="block w-full text-sm text-gray-700 dark:text-gray-300 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-900/50 dark:file:text-primary-300" />
                                <InputError :message="form.errors.favicon" class="mt-2" />
                                <div v-if="props.settings?.favicon_path" class="mt-3">
                                    <img :src="`/storage/${props.settings.favicon_path}`" alt="Favicon" class="h-8 w-8 rounded" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                URL da Evolution API
                            </label>
                            <TextInput v-model="form.evolution_api_base_url" class="w-full" placeholder="https://api.seu-vps.com" />
                        </div>

                        <!-- Webhooks WhatsApp -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-6 space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Webhooks WhatsApp</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-3">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" id="whapi_enabled" v-model="form.whapi_webhook_enabled" />
                                        <label for="whapi_enabled" class="text-sm">Habilitar Webhook Whapi</label>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Segredo (Whapi)
                                        </label>
                                        <TextInput v-model="form.whapi_webhook_secret" class="w-full" placeholder="Chave secreta para validação" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            URL do Webhook (Whapi)
                                        </label>
                                        <TextInput v-model="form.whapi_webhook_url" class="w-full" placeholder="https://seu-dominio/webhooks/whatsapp/status" />
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            Recomenda-se usar: {{ currentOrigin }}/webhooks/whatsapp/status
                                        </p>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" id="evo_enabled" v-model="form.evolution_webhook_enabled" />
                                        <label for="evo_enabled" class="text-sm">Habilitar Webhook Evolution</label>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Segredo (Evolution)
                                        </label>
                                        <TextInput v-model="form.evolution_webhook_secret" class="w-full" placeholder="Chave secreta para validação" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            URL do Webhook (Evolution)
                                        </label>
                                        <TextInput v-model="form.evolution_webhook_url" class="w-full" placeholder="https://seu-dominio/webhooks/whatsapp/status" />
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            Recomenda-se usar: {{ currentOrigin }}/webhooks/whatsapp/status
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                <p class="mb-2">Após salvar, configure no painel do provedor:</p>
                                <ul class="list-disc list-inside">
                                    <li>Whapi: defina Webhook URL e inclua um cabeçalho X-Webhook-Secret com o segredo configurado.</li>
                                    <li>Evolution: defina Webhook URL e inclua um cabeçalho X-Webhook-Secret com o segredo configurado.</li>
                                </ul>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton>
                                Salvar Configurações
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
