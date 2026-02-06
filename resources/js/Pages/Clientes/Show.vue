<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PhoneInput from '@/Components/PhoneInput.vue';

const props = defineProps({
    cliente: Object,
    whatsappNumbers: Array,
    invoices: Array,
    templates: Array,
});

const localId = computed(() => props.cliente.local_id || props.cliente.id);
const editingContact = ref(false);
const contactForm = useForm({
    phone: props.cliente.phone || props.cliente.telefone_comercial || '',
    mobile_phone: props.cliente.mobile_phone || props.cliente.telefone_celular || '',
    birthdate: props.cliente.birthdate || props.cliente.data_nascimento || '',
});
const contactSaved = ref(false);
const saveContact = () => {
    contactForm.put(route('clientes.update', localId.value), {
        preserveScroll: true,
        onSuccess: () => {
            editingContact.value = false;
            contactSaved.value = true;
            if (isMessageModalOpen.value) {
                form.to = formatPhoneForWhatsapp(contactForm.mobile_phone || contactForm.phone);
            }
        },
    });
};

watch(() => [contactForm.mobile_phone, contactForm.phone], () => {
    if (isMessageModalOpen.value) {
        form.to = formatPhoneForWhatsapp(contactForm.mobile_phone || contactForm.phone);
    }
});

const isMessageModalOpen = ref(false);
const isInvoicesModalOpen = ref(false);

// Garante que templatesList seja sempre um array válido
const templatesList = computed(() => {
    if (Array.isArray(props.templates)) return props.templates;
    // Se vier como objeto (Collection serializada as vezes), tenta converter
    if (props.templates && typeof props.templates === 'object') return Object.values(props.templates);
    return [];
});

const selectedTemplate = ref('');
const messageType = ref('template');
const emojiPickerOpen = ref(false);
const textareaRef = ref(null);

// Debug para verificar se templates estão chegando
onMounted(() => {
    console.log('Show.vue montado. Templates:', props.templates);
});

// Watch para aplicar template automaticamente ao mudar o tipo
watch(messageType, (newVal) => {
    if (newVal === 'template') {
        // Se já tem um selecionado, reaplica (útil se o usuário editou e quer voltar)
        if (selectedTemplate.value) {
            applyTemplate(selectedTemplate.value);
        } 
        // Se não tem selecionado, mas tem lista, seleciona o primeiro
        else if (templatesList.value.length > 0) {
            selectedTemplate.value = templatesList.value[0].id;
            applyTemplate(templatesList.value[0].id);
        }
    }
});

const formatPhoneForWhatsapp = (phone) => {
    const original = (phone || '').toString();
    let digits = original.replace(/\D/g, '');
    const hasPlus = original.trim().startsWith('+');

    // Remove leading zero
    if (digits.startsWith('0')) digits = digits.substring(1);

    // Se o usuário digitou com '+', não forçar 55
    if (hasPlus) {
        return digits || '55';
    }

    // Já possui DDI (>=12 dígitos)
    if (digits.length >= 12) {
        return digits;
    }

    // 11 dígitos: preservar se parecer internacional (ex.: inicia com 1/NANP ou códigos comuns de 2 dígitos)
    if (digits.length === 11 && !digits.startsWith('55')) {
        const oneDigit = ['1', '7'];
        const twoDigit = ['20','27','30','31','32','33','34','36','39','40','41','43','44','45','46','47','48','49','51','52','53','54','56','57','58','60','61','62','63','64','65','66','81','82','84','86','90','91','92','93','94','95','98','99'];
        const startsInternational = oneDigit.includes(digits[0]) || twoDigit.includes(digits.slice(0,2));
        if (startsInternational) {
            return digits;
        }
    }

    // Caso típico BR: prefixa 55
    if (!digits.startsWith('55')) {
        digits = '55' + digits;
    }

    return digits || '55';
};

const form = useForm({
    whatsapp_id: '',
    to: formatPhoneForWhatsapp(contactForm.mobile_phone || contactForm.phone),
    message: '',
});

// Emoji list (common ones)
const emojis = [
    '😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘',
    '😗', '😙', '😚', '😋', '😛', '😝', '😜', '🤪', '🤨', '🧐', '🤓', '😎', '🤩', '🥳', '😏', '😒',
    '😞', '😔', '😟', '😕', '🙁', '☹️', '😣', '😖', '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡',
    '👋', '🤚', '🖐', '✋', '🖖', '👌', '🤌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆',
    '👇', '👍', '👎', '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅', '🤳',
    '💪', '🧠', '🦴', '👀', '👁', '💋', '👄', '🦷', '👅', '👂', '🦻', '👃', '👣', '👁️‍🗨️', '📢', '📣',
    '🔔', '🔕', '🎼', '🎵', '🎶', '🎤', '🎧', '📻', '🎷', '🎸', '🎹', '🎺', '🎻', '🪕', '🥁', '📱',
    '📲', '☎️', '📞', '📟', '📠', '🔋', '🔌', '💻', '🖥', '🖨', '⌨️', '🖱', '🖲', '💽', '💾', '💿',
    '💰', '💴', '💵', '💶', '💷', '💸', '💳', '🧾', '💹', '✉️', '📧', '📨', '📩', '📤', '📥', '📦',
    '📫', '📪', '📬', '📭', '📮', '🗳', '✏️', '✒️', '🖋', '🖊', '🖌', '🖍', '📝', '💼', '📁', '📂',
    '📅', '📆', '🗒', '🗓', '📇', '📈', '📉', '📊', '📋', '📌', '📍', '📎', '🖇', '📏', '📐', '✂️',
    '🔒', '🔓', '🔏', '🔐', '🔑', '🗝', '🔨', '🪓', '⛏', '⚒', '🛠', '🗡', '⚔️', '🔫', '🪃', '🏹',
    '🛡', '🔧', '🪛', '🔩', '⚙️', '🧰', '🧲', '🪜', '🪞', '🪟', '🛏', '🛋', '🪑', '🚽', '🪠', '🚿', '🛁', '🧼', '🪒', '🧴', '🧷', '🧹', '🧺', '🧻', '🪣', '🧼', '🪥',
    '🧽', '🧯', '🛒', '🚬', '⚰️', '🪦', '⚱️', '🗿', '🪧', '✅', '✔️', '☑️', '❌', '❎', '➕', '➖',
    '➗', '➰', '➿', '〽️', '✳️', '✴️', '❇️', '‼️', '⁉️', '❓', '❔', '❕', '❗', '〰️', '©️', '®️',
    '™️', '#️⃣', '*️⃣', '0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟',
    '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⚫', '⚪', '🟤', '🔺', '🔻', '🔸', '🔹', '🔶', '🔷', '🔳',
    '🔲', '▪️', '▫️', '◾', '◽', '◼️', '◻️', '🟥', '🟧', '🟨', '🟩', '🟦', '🟪', '⬛', '⬜', '🟫',
];

const insertEmoji = (emoji) => {
    const textarea = textareaRef.value;
    if (!textarea) {
        form.message += emoji;
        return;
    }

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = form.message;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);

    form.message = before + emoji + after;
    emojiPickerOpen.value = false;

    setTimeout(() => {
        textarea.focus();
        textarea.setSelectionRange(start + emoji.length, start + emoji.length);
    }, 0);
};

const toggleEmojiPicker = () => {
    emojiPickerOpen.value = !emojiPickerOpen.value;
};

// Helper functions needed for applyTemplate
const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    // Append T12:00:00 to avoid timezone issues shifting the date
    const date = new Date(dateString.includes('T') ? dateString : dateString + 'T12:00:00');
    return date.toLocaleDateString('pt-BR');
};

const adjustForWeekend = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString.includes('T') ? dateString : dateString + 'T12:00:00');
    const day = date.getDay();
    if (day === 6) { // Saturday -> Monday
        date.setDate(date.getDate() + 2);
    } else if (day === 0) { // Sunday -> Monday
        date.setDate(date.getDate() + 1);
    }
    return date.toLocaleDateString('pt-BR');
};

const calculateLateDays = (dateString) => {
    if (!dateString) return 0;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dueDate = new Date(dateString.includes('T') ? dateString : dateString + 'T12:00:00');
    
    if (dueDate >= today) return 0;
    
    const diffTime = Math.abs(today - dueDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
    return diffDays;
};

const applyTemplate = (templateId) => {
    const template = templatesList.value.find(t => t.id == templateId);
    if (!template) return;

    let content = template.content;
    const client = props.cliente;
    const overdueInvoices = props.invoices || [];

    // Calculate totals
    const totalValue = overdueInvoices.reduce((sum, inv) => sum + Number(inv.saldo_devedor || inv.valor_original || 0), 0);
    const overdueDates = overdueInvoices.map(inv => {
        if (!inv.data_vencimento) return '';
        return inv.data_vencimento.split('T')[0].split('-').reverse().join('/');
    }).filter(Boolean).join(', ');

    const boletoUrls = overdueInvoices.map(inv => inv.link_boleto).filter(Boolean).join('\n');
    const firstBoletoUrl = overdueInvoices.find(inv => inv.link_boleto)?.link_boleto || '';
    const firstInvoice = overdueInvoices[0] || {};
    const pairs = overdueInvoices.map(inv => {
        const d = inv.data_vencimento ? formatDate(inv.data_vencimento) : '-';
        return `${d} - ${inv.link_boleto || ''}`;
    }).join('\n');

    // Variables replacement logic
    const replacements = {
        '@@clientName@@': client.nome || client.name || 'Cliente',
        '@@clientCompany@@': client.nome_fantasia || client.company_name || client.razao_social || '',
        '@@clientEmail@@': client.email || '',
        '@@invoicePastDueQuantity@@': overdueInvoices.length.toString(),
        '@@invoicePastDueDates@@': overdueDates,
        '@@invoiceTotalValue@@': formatCurrency(totalValue),
        '@@invoiceBoletoUrls@@': boletoUrls,
        '@@invoiceBoletoUrl@@': firstBoletoUrl,
        '@@invoicePastDuePairs@@': pairs,
        '@@invoiceDueDate@@': adjustForWeekend(firstInvoice.data_vencimento),
        '@@invoiceStrictDueDate@@': formatDate(firstInvoice.data_vencimento),
        '@@invoiceUrl@@': firstInvoice.link_boleto || '',
        '@@invoiceOpenValue@@': formatCurrency(firstInvoice.saldo_devedor || firstInvoice.valor_original || 0),
        '@@invoiceLateDays@@': calculateLateDays(firstInvoice.data_vencimento)
    };

    Object.keys(replacements).forEach(key => {
        content = content.replace(new RegExp(key, 'g'), replacements[key]);
    });

    form.message = content;
};

const openMessageModal = () => {
    // Definir número padrão se houver
    const defaultNumber = props.whatsappNumbers.find(n => n.is_default);
    if (defaultNumber) {
        form.whatsapp_id = defaultNumber.id;
    } else if (props.whatsappNumbers.length > 0) {
        form.whatsapp_id = props.whatsappNumbers[0].id;
    }
    
    selectedTemplate.value = '';

    // Check for default template first
    const defaultTemplate = templatesList.value.find(t => t.is_default);
    if (defaultTemplate) {
        messageType.value = 'template';
        selectedTemplate.value = defaultTemplate.id;
        applyTemplate(defaultTemplate.id);
    } 
    // Fallback to hardcoded logic if no default template and there are invoices
    else if (props.invoices && props.invoices.length > 0) {
        messageType.value = 'custom';
        const total = props.invoices.reduce((sum, inv) => sum + Number(inv.saldo_devedor || inv.valor_original || 0), 0);
        const formattedTotal = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(total);
        const count = props.invoices.length;
        const clienteNome = props.cliente.nome || props.cliente.name || 'Cliente';
        
        let msg = `Olá ${clienteNome}, identificamos ${count} fatura(s) em aberto totalizando ${formattedTotal}.\n\n`;

        props.invoices.slice(0, 5).forEach(inv => {
            const val = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(inv.saldo_devedor || inv.valor_original || 0);
            // Handle date format
            let venc = '-';
            if (inv.data_vencimento) {
                 venc = inv.data_vencimento.split('T')[0].split('-').reverse().join('/');
            }
            msg += `- Venc: ${venc} | Valor: ${val}\n`;
            if (inv.link_boleto) {
                msg += `  Boleto: ${inv.link_boleto}\n`;
            }
        });

        if (props.invoices.length > 5) {
            msg += `... e mais ${props.invoices.length - 5} fatura(s).\n`;
        }
        
        msg += `\nPor favor, entre em contato para regularização.`;
        
        form.message = msg;
    } else {
        messageType.value = 'custom';
        form.message = '';
    }

    isMessageModalOpen.value = true;
};

const closeMessageModal = () => {
    isMessageModalOpen.value = false;
    form.reset();
    form.to = formatPhoneForWhatsapp(contactForm.mobile_phone || contactForm.phone);
};

const openInvoicesModal = () => {
    isInvoicesModalOpen.value = true;
};

const closeInvoicesModal = () => {
    isInvoicesModalOpen.value = false;
};

const sendMessage = () => {
    form.post(route('messages.send'), {
        onSuccess: () => {
            closeMessageModal();
            // Opcional: mostrar toast
        },
    });
};

// WhatsApp Error Handling
const showWhatsappErrorModal = ref(false);
const whatsappErrorData = ref(null);
const selectedRetryNumberId = ref('');
const page = usePage();

watch(() => page.props.flash.whatsapp_error, (newVal) => {
    if (newVal) {
        whatsappErrorData.value = newVal;
        showWhatsappErrorModal.value = true;
        // Pre-select the first available number if any
        if (newVal.available_numbers && newVal.available_numbers.length > 0) {
            selectedRetryNumberId.value = newVal.available_numbers[0].id;
        }
    }
}, { deep: true });

const closeWhatsappErrorModal = () => {
    showWhatsappErrorModal.value = false;
    whatsappErrorData.value = null;
    page.props.flash.whatsapp_error = null;
};

const retrySend = () => {
    if (!selectedRetryNumberId.value) return;
    
    // Update form with new number
    form.whatsapp_id = selectedRetryNumberId.value;
    closeWhatsappErrorModal();
    
    // Retry sending
    sendMessage();
};
</script>

<template>
    <Head :title="`Cliente: ${cliente.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Detalhes do Cliente
                </h2>
                <div class="flex gap-4">
                    <PrimaryButton @click="openInvoicesModal" class="bg-red-600 hover:bg-red-700">
                        Ver Faturas em Atraso ({{ invoices.length }})
                    </PrimaryButton>
                    <PrimaryButton @click="openMessageModal" class="bg-green-600 hover:bg-green-700">
                        Enviar WhatsApp
                    </PrimaryButton>
                    <Link :href="route('clientes.index')" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150">
                        &larr; Voltar
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Info Básica -->
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg shadow-sm">
                                <h3 class="text-lg font-semibold mb-4 border-b pb-2">Informações Pessoais</h3>
                                <div class="space-y-2">
                                    <!-- API v2: nome, nome_fantasia, tipo_pessoa, cpf, cnpj, data_nascimento -->
                                    <p><span class="font-bold">Nome:</span> {{ cliente.nome || cliente.name }}</p>
                                    <p><span class="font-bold">Nome Fantasia:</span> {{ cliente.nome_fantasia || '-' }}</p>
                                    <p><span class="font-bold">Vinculado à Empresa:</span> {{ cliente.company_name || '-' }}</p>
                                    <p><span class="font-bold">Tipo:</span> {{ cliente.tipo_pessoa || cliente.person_type }}</p>
                                    <p><span class="font-bold">Documento:</span> {{ cliente.cpf || cliente.cnpj || cliente.document }}</p>
                                    <p><span class="font-bold">Data Nascimento:</span> {{ contactForm.birthdate || cliente.data_nascimento || cliente.date_of_birth }}</p>
                                </div>
                            </div>

                            <!-- Contato -->
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg shadow-sm">
                                <h3 class="text-lg font-semibold mb-4 border-b pb-2">Contato</h3>
                                <div v-if="!editingContact" class="space-y-2">
                                    <p><span class="font-bold">Email:</span> {{ cliente.email }}</p>
                                    <p><span class="font-bold">Telefone:</span> {{ contactForm.phone || cliente.telefone_comercial || cliente.phone }}</p>
                                    <p><span class="font-bold">Celular:</span> {{ contactForm.mobile_phone || cliente.telefone_celular || cliente.mobile_phone }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-300">Em uso para WhatsApp: {{ contactForm.mobile_phone ? 'Celular' : 'Telefone' }}</p>
                                    <div v-if="contactSaved" class="mt-2 px-3 py-2 rounded bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 text-sm">
                                        Dados de contato atualizados com sucesso.
                                    </div>
                                    <PrimaryButton class="mt-3" @click="editingContact = true">Editar Contato</PrimaryButton>
                                </div>
                                <div v-else class="space-y-3">
                                    <div>
                                        <InputLabel for="phone" value="Telefone" />
                                        <PhoneInput id="phone" v-model="contactForm.phone" class="mt-1 block w-full" />
                                        <InputError class="mt-2" :message="contactForm.errors.phone" />
                                    </div>
                                    <div>
                                        <InputLabel for="mobile_phone" value="Celular" />
                                        <PhoneInput id="mobile_phone" v-model="contactForm.mobile_phone" class="mt-1 block w-full" />
                                        <InputError class="mt-2" :message="contactForm.errors.mobile_phone" />
                                    </div>
                                    <div>
                                        <InputLabel for="birthdate" value="Data de Nascimento" />
                                        <input id="birthdate" type="date" v-model="contactForm.birthdate" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm" />
                                        <InputError class="mt-2" :message="contactForm.errors.birthdate" />
                                    </div>
                                    <div class="flex gap-2 mt-2">
                                        <SecondaryButton @click="editingContact = false">Cancelar</SecondaryButton>
                                        <PrimaryButton @click="saveContact">Salvar</PrimaryButton>
                                    </div>
                                </div>
                            </div>

                            <!-- Endereço -->
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg shadow-sm md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 border-b pb-2">Endereço</h3>
                                <div class="space-y-2">
                                    <!-- API v2: enderecos array -->
                                    <p v-if="cliente.enderecos && cliente.enderecos.length > 0">
                                        {{ cliente.enderecos[0].logradouro }}, {{ cliente.enderecos[0].numero }} 
                                        {{ cliente.enderecos[0].complemento ? '- ' + cliente.enderecos[0].complemento : '' }} <br>
                                        {{ cliente.enderecos[0].bairro }} - {{ cliente.enderecos[0].cidade?.nome || cliente.enderecos[0].cidade }} / {{ cliente.enderecos[0].estado?.sigla || cliente.enderecos[0].estado }} <br>
                                        CEP: {{ cliente.enderecos[0].cep }}
                                    </p>
                                    <p v-else-if="cliente.address">
                                        {{ cliente.address.street }}, {{ cliente.address.number }} 
                                        {{ cliente.address.complement ? '- ' + cliente.address.complement : '' }} <br>
                                        {{ cliente.address.neighborhood }} - {{ cliente.address.city?.name }} / {{ cliente.address.state?.name }} <br>
                                        CEP: {{ cliente.address.zip_code }}
                                    </p>
                                    <p v-else class="text-gray-500">Endereço não informado.</p>
                                </div>
                            </div>

                            <!-- Outras infos (Observações, etc) -->
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg shadow-sm md:col-span-2" v-if="cliente.observacoes || cliente.observations">
                                <h3 class="text-lg font-semibold mb-4 border-b pb-2">Observações</h3>
                                <p class="whitespace-pre-line">{{ cliente.observacoes || cliente.observations }}</p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de Faturas em Atraso -->
        <Modal :show="isInvoicesModalOpen" @close="closeInvoicesModal">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Faturas em Atraso
                    </h2>
                    <SecondaryButton @click="closeInvoicesModal"> Fechar </SecondaryButton>
                </div>

                <div v-if="invoices.length === 0" class="text-gray-500 text-center py-4">
                    Nenhuma fatura em atraso encontrada para este cliente.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Vencimento</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Descrição</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Valor</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="invoice in invoices" :key="invoice.id">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ invoice.data_vencimento ? new Date(invoice.data_vencimento + 'T12:00:00').toLocaleDateString('pt-BR') : '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ invoice.descricao || 'Fatura sem descrição' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ invoice.total || invoice.valor_original ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(invoice.valor_original || invoice.total) : '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                        {{ invoice.status_traduzido || invoice.status }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Modal>

        <!-- Modal de Envio de Mensagem -->
        <Modal :show="isMessageModalOpen" @close="closeMessageModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    Enviar Mensagem WhatsApp
                </h2>

                <form @submit.prevent="sendMessage" class="mt-6 space-y-4">
                    
                    <div>
                        <InputLabel for="whatsapp_id" value="Enviar de:" />
                        <select
                            id="whatsapp_id"
                            v-model="form.whatsapp_id"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                            required
                        >
                            <option v-for="number in whatsappNumbers" :key="number.id" :value="number.id">
                                {{ number.description }} ({{ number.ddi }} {{ number.ddd }} {{ number.phone }})
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.whatsapp_id" />
                    </div>

                    <div>
                        <InputLabel for="to" value="Para (Número Completo):" />
                        <PhoneInput
                            id="to"
                            class="mt-1 block w-full"
                            v-model="form.to"
                            required
                        />
                         <p class="text-sm text-gray-500 mt-1">Aceita DDI. Somente dígitos. Ex.: 5511999999999 ou 351912345678.</p>
                        <InputError class="mt-2" :message="form.errors.to" />
                    </div>

                    <!-- Message Type Selection -->
                    <div class="flex gap-4">
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="radio" v-model="messageType" value="template" class="form-radio text-primary-600 border-gray-300 focus:ring-primary-500">
                            <span class="ml-2 text-gray-700 dark:text-gray-300">Mensagem Padrão</span>
                        </label>
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="radio" v-model="messageType" value="custom" class="form-radio text-primary-600 border-gray-300 focus:ring-primary-500">
                            <span class="ml-2 text-gray-700 dark:text-gray-300">Texto Personalizado</span>
                        </label>
                    </div>

                    <!-- Template Selection -->
                    <div v-if="messageType === 'template'">
                        <InputLabel for="template_id" value="Modelo de Mensagem" />
                        <select 
                            id="template_id" 
                            v-model="selectedTemplate" 
                            @change="applyTemplate($event.target.value)"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                        >
                            <option value="">Selecione um modelo...</option>
                            <option v-for="template in templatesList" :key="template.id" :value="template.id">
                                {{ template.name }} {{ template.is_default ? '(Padrão)' : '' }}
                            </option>
                            <option v-if="templatesList.length === 0" disabled>Nenhum modelo disponível</option>
                        </select>
                    </div>

                    <div class="relative">
                        <div class="flex justify-between items-center mb-1">
                            <InputLabel for="message" value="Mensagem:" />
                            <div class="flex items-center gap-2">
                                <button @click="toggleEmojiPicker" type="button" class="text-gray-500 hover:text-yellow-500 transition-colors" title="Inserir Emoji">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Emoji Picker -->
                        <div v-if="emojiPickerOpen" class="absolute right-0 bottom-full mb-2 z-50 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg p-2 w-64 grid grid-cols-8 gap-1 max-h-48 overflow-y-auto custom-scrollbar">
                            <button v-for="emoji in emojis" :key="emoji" @click="insertEmoji(emoji)" type="button" class="hover:bg-gray-100 dark:hover:bg-gray-600 rounded p-1 text-lg">
                                {{ emoji }}
                            </button>
                        </div>

                        <textarea
                            id="message"
                            ref="textareaRef"
                            v-model="form.message"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm font-mono text-sm"
                            rows="6"
                            required
                        ></textarea>
                        <InputError class="mt-2" :message="form.errors.message" />
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="closeMessageModal"> Cancelar </SecondaryButton>
                        <PrimaryButton class="ms-3 bg-green-600 hover:bg-green-700" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            Enviar
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Modal Erro WhatsApp -->
        <Modal :show="showWhatsappErrorModal" @close="closeWhatsappErrorModal">
            <div class="p-6">
                <div class="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 rounded-full dark:bg-red-900">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                </div>
                <div class="mt-3 text-center sm:mt-5">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100" id="modal-title">
                        {{ whatsappErrorData?.title || 'Erro no Envio' }}
                    </h3>
                    <div class="mt-2">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ whatsappErrorData?.message }}
                        </p>
                    </div>
                </div>

                <div class="mt-5 sm:mt-6" v-if="whatsappErrorData?.available_numbers?.length > 0">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Selecione outro número para enviar:
                    </label>
                    <select v-model="selectedRetryNumberId" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm">
                        <option v-for="num in whatsappErrorData.available_numbers" :key="num.id" :value="num.id">
                            {{ num.description }} ({{ num.ddi }}{{ num.ddd }}{{ num.phone }})
                        </option>
                    </select>
                </div>
                <div class="mt-5 sm:mt-6" v-else>
                    <p class="text-sm text-red-500 text-center font-bold">Não há outros números ativos disponíveis.</p>
                </div>

                <div class="mt-5 sm:mt-6 flex gap-3 justify-end">
                    <SecondaryButton @click="closeWhatsappErrorModal">
                        Cancelar Envio
                    </SecondaryButton>
                    <PrimaryButton 
                        @click="retrySend" 
                        class="bg-red-600 hover:bg-red-700 focus:ring-red-500"
                        :disabled="!selectedRetryNumberId"
                        v-if="whatsappErrorData?.available_numbers?.length > 0"
                    >
                        Confirmar Novo Envio
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
