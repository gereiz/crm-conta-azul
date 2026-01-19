<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, nextTick } from 'vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PhoneInput from '@/Components/PhoneInput.vue';

const props = defineProps({
    invoices: Object,
    pagination: Object,
    filters: Object,
    whatsappNumbers: Array,
    templates: Array,
    paymentTypes: Array,
    connections: Array,
});

const invoicesList = computed(() => (props.invoices?.data || []).filter(Boolean));
const templatesList = computed(() => props.templates || []);
const currentPage = computed(() => props.invoices?.current_page || 1);
const totalPages = computed(() => props.invoices?.last_page || 1);

const hasPrev = computed(() => props.invoices?.prev_page_url !== null);
const hasNext = computed(() => props.invoices?.next_page_url !== null);

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

const changePage = (page) => {
    router.get(route('clientes.invoices.overdue'), { 
        page: page,
        size: props.invoices?.per_page || 20,
        start_date: startDate.value,
        end_date: endDate.value,
        payment_type: selectedPaymentType.value,
        search: searchQuery.value,
        connection_id: selectedCompanyId.value
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    // Handle full ISO strings (e.g. 2025-12-30T03:00:00.000000Z) or simple dates (2025-12-30)
    const datePart = dateString.split('T')[0];
    const [year, month, day] = datePart.split('-');
    return `${day}/${month}/${year}`;
};

const adjustForWeekend = (dateString) => {
    if (!dateString) return '-';
    const datePart = dateString.split('T')[0];
    const [year, month, day] = datePart.split('-');
    const date = new Date(year, month - 1, day);
    const dayOfWeek = date.getDay(); // 0 = Sunday, 6 = Saturday
    
    if (dayOfWeek === 6) { // Saturday
        date.setDate(date.getDate() + 2);
    } else if (dayOfWeek === 0) { // Sunday
        date.setDate(date.getDate() + 1);
    }
    
    return date.toLocaleDateString('pt-BR');
};

const calculateLateDays = (dateString) => {
    if (!dateString) return '0';
    const datePart = dateString.split('T')[0];
    const [year, month, day] = datePart.split('-');
    const dueDate = new Date(year, month - 1, day);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const diffTime = today - dueDate;
    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
    return diffDays > 0 ? diffDays.toString() : '0';
};

// Filter Logic
const startDate = ref(props.filters?.start_date || '');
const endDate = ref(props.filters?.end_date || '');
const searchQuery = ref(props.filters?.search || '');
const selectedPaymentType = ref(props.filters?.payment_type || '');
const selectedCompanyId = ref(props.filters?.connection_id || '');

const applyFilter = () => {
    router.get(route('clientes.invoices.overdue'), { 
        page: 1, // Reset page on filter change
        size: props.invoices?.per_page || 20,
        start_date: startDate.value,
        end_date: endDate.value,
        search: searchQuery.value,
        payment_type: selectedPaymentType.value,
        connection_id: selectedCompanyId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilter = () => {
    startDate.value = '';
    endDate.value = '';
    searchQuery.value = '';
    selectedPaymentType.value = '';
    selectedCompanyId.value = '';
    applyFilter();
};

// Modal Logic
const showModal = ref(false);
const selectedInvoice = ref(null);
const clientInvoices = ref([]);
const isLoadingInvoices = ref(false);
const emojiPickerOpen = ref(false);
const textareaRef = ref(null);
const selectedTemplate = ref('');
const messageType = ref('template');

const msgForm = useForm({
    whatsapp_id: '',
    to: '',
    message: '',
    connection_id: '',
    cliente_nome: '',
    cliente_ca_id: '',
    invoice_ca_id: '',
    descricao: '',
});

const applyTemplate = (templateId) => {
    const template = templatesList.value.find(t => t.id == templateId);
    if (!template || !selectedInvoice.value) return;

    let content = template.content;
    const invoice = selectedInvoice.value;
    const client = invoice.cliente;

    // Use fetched invoices if available, otherwise fallback to single invoice
    const invoicesToUse = clientInvoices.value.length > 0 ? clientInvoices.value : [invoice];

    // Calculate totals
    const totalValue = invoicesToUse.reduce((sum, inv) => sum + Number(inv.saldo_devedor || inv.nao_pago || 0), 0);
    const overdueDates = invoicesToUse.map(inv => formatDate(inv.data_vencimento)).join(', ');

    const boletoUrls = invoicesToUse.map(inv => inv.link_boleto).filter(Boolean).join('\n');
    const firstBoletoUrl = invoicesToUse.find(inv => inv.link_boleto)?.link_boleto || '';
    const pairs = invoicesToUse.map(inv => `${formatDate(inv.data_vencimento)} - ${inv.link_boleto || ''}`).join('\n');

    // Variables replacement logic
    const replacements = {
        '@@clientName@@': client?.name || client?.nome || 'Cliente',
        '@@clientCompany@@': client?.company_name || client?.razao_social || '',
        '@@clientEmail@@': client?.email || '',
        '@@invoicePastDueQuantity@@': invoicesToUse.length.toString(),
        '@@invoicePastDueDates@@': overdueDates,
        '@@invoiceTotalValue@@': formatCurrency(totalValue),
        '@@invoiceBoletoUrls@@': boletoUrls,
        '@@invoiceBoletoUrl@@': firstBoletoUrl,
        '@@invoicePastDuePairs@@': pairs,
        '@@invoiceDueDate@@': adjustForWeekend(invoice.data_vencimento),
        '@@invoiceStrictDueDate@@': formatDate(invoice.data_vencimento),
        '@@invoiceUrl@@': invoice.link_boleto || '',
        '@@invoiceOpenValue@@': formatCurrency(invoice.saldo_devedor || invoice.nao_pago || 0),
        '@@invoiceLateDays@@': calculateLateDays(invoice.data_vencimento)
    };

    Object.keys(replacements).forEach(key => {
        content = content.replace(new RegExp(key, 'g'), replacements[key]);
    });

    msgForm.message = content;
};

const insertEmoji = (emoji) => {
    const textarea = textareaRef.value;
    if (!textarea) {
        msgForm.message += emoji;
        return;
    }

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = msgForm.message;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);

    msgForm.message = before + emoji + after;
    emojiPickerOpen.value = false;

    setTimeout(() => {
        textarea.focus();
        textarea.setSelectionRange(start + emoji.length, start + emoji.length);
    }, 0);
};

const toggleEmojiPicker = () => {
    emojiPickerOpen.value = !emojiPickerOpen.value;
};

const formatPhoneForWhatsapp = (phone) => {
    let digits = (phone || '').toString().replace(/\D/g, '');
    
    // Remove leading zero
    if (digits.startsWith('0')) digits = digits.substring(1);

    // Force add 55 if missing
    if (!digits.startsWith('55') || (digits.length >= 10 && digits.length <= 12)) {
         if (!digits.startsWith('55')) {
            digits = '55' + digits;
         }
    }
    
    // Default
    if (!digits) digits = '55';
    
    return digits;
};

const openDetails = async (invoice) => {
    selectedInvoice.value = invoice;
    showModal.value = true;
    selectedTemplate.value = '';
    clientInvoices.value = [];
    isLoadingInvoices.value = true;

    // Prepare WhatsApp Form
    if (props.whatsappNumbers && props.whatsappNumbers.length > 0) {
        const defaultNumber = props.whatsappNumbers.find(n => n.is_default);
        msgForm.whatsapp_id = defaultNumber ? defaultNumber.id : props.whatsappNumbers[0].id;
    }

    const clientPhone = invoice.cliente?.mobile_phone || invoice.cliente?.phone || invoice.cliente?.telefone || '';
    
    // Use nextTick to ensure the form value is updated after modal opens/reactivity settles
    msgForm.to = ''; // Clear first to ensure change detection
    nextTick(() => {
        msgForm.to = formatPhoneForWhatsapp(clientPhone);
    });
    msgForm.connection_id = invoice.connection_id || selectedCompanyId.value || '';
    msgForm.cliente_nome = invoice.cliente_nome || invoice.cliente?.name || invoice.cliente?.nome || '';
    msgForm.cliente_ca_id = invoice.cliente_ca_id || '';
    msgForm.invoice_ca_id = invoice.ca_id || '';
    msgForm.descricao = invoice.descricao || '';

    // Fetch all overdue invoices for this client to support grouping
    if (invoice.cliente?.id || invoice.cliente_ca_id) {
        try {
            const clientId = invoice.cliente?.id || invoice.cliente_ca_id;
            const response = await axios.get(route('clientes.invoices.json', clientId), {
                params: {
                    start_date: startDate.value,
                    end_date: endDate.value
                }
            });
            clientInvoices.value = response.data.invoices || [];
        } catch (error) {
            console.error('Error fetching client invoices:', error);
            clientInvoices.value = [invoice]; // Fallback
        } finally {
            isLoadingInvoices.value = false;
            // Re-apply template if currently in template mode and a template is selected to update totals
            if (messageType.value === 'template' && selectedTemplate.value) {
                applyTemplate(selectedTemplate.value);
            }
        }
    } else {
        clientInvoices.value = [invoice];
        isLoadingInvoices.value = false;
    }

    // Check for default template
    const defaultTemplate = templatesList.value.find(t => t.is_default);
    if (defaultTemplate) {
        messageType.value = 'template';
        selectedTemplate.value = defaultTemplate.id;
        applyTemplate(defaultTemplate.id);
    } else {
        messageType.value = 'custom';
        // Fallback to hardcoded message (updated to support grouping if manually triggered?)
        // For hardcoded, we keep it simple or update if user wants
        const valor = formatCurrency(invoice.saldo_devedor || invoice.nao_pago || 0);
        const vencimento = formatDate(invoice.data_vencimento);
        const clienteNome = invoice.cliente_nome || invoice.cliente?.nome || 'Cliente';

        msgForm.message = `Olá ${clienteNome}, gostaríamos de lembrar sobre a fatura com vencimento em ${vencimento} no valor de ${valor} que consta em aberto.\n\nPor favor, entre em contato para regularização.`;
    }
};

const closeModal = () => {
    showModal.value = false;
    selectedInvoice.value = null;
    msgForm.reset();
    emojiPickerOpen.value = false;
    selectedTemplate.value = '';
};

const sendWhatsapp = () => {
    msgForm.post(route('messages.send'), {
        preserveScroll: true,
        onSuccess: () => {
            // Optional: close modal or just show success message (handled by layout flash)
            // closeModal(); 
            msgForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Faturas em Atraso" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Faturas em Atraso (Conta Azul)
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                
                <!-- Filtros -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6 p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Filtros</h3>
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
                        <div class="md:col-span-1">
                            <InputLabel for="search" value="Buscar Cliente" />
                            <TextInput
                                id="search"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="searchQuery"
                                placeholder="Digite o nome do cliente"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <InputLabel for="start_date" value="Data de Vencimento (De)" />
                            <TextInput
                                id="start_date"
                                type="date"
                                class="mt-1 block w-full"
                                v-model="startDate"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <InputLabel for="end_date" value="Data de Vencimento (Até)" />
                            <TextInput
                                id="end_date"
                                type="date"
                                class="mt-1 block w-full"
                                v-model="endDate"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <InputLabel for="payment_type" value="Tipo de Pagamento" />
                            <select
                                id="payment_type"
                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                v-model="selectedPaymentType"
                            >
                                <option value="">Todos</option>
                                <option v-for="type in paymentTypes" :key="type" :value="type">
                                    {{ type }}
                                </option>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <InputLabel for="company" value="Empresa" />
                            <select
                                id="company"
                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                v-model="selectedCompanyId"
                            >
                                <option value="">Todas</option>
                                <option v-for="c in connections" :key="c.id" :value="c.id">
                                    {{ c.empresa_nome }}
                                </option>
                            </select>
                        </div>
                        <div class="md:col-span-1 flex gap-2">
                            <PrimaryButton @click="applyFilter">
                                Filtrar
                            </PrimaryButton>
                            <SecondaryButton @click="clearFilter">
                                Limpar
                            </SecondaryButton>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Cliente</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Vencimento</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tipo Pagto.</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Valor Original</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Saldo Devedor</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="invoice in invoicesList" :key="invoice.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition cursor-pointer" @click="openDetails(invoice)">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ invoice.cliente_nome || invoice.cliente?.nome || 'Cliente Desconhecido' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ formatDate(invoice.data_vencimento) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ invoice.payment_type || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ formatCurrency(invoice.valor_original || invoice.total || 0) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-bold">
                                        {{ formatCurrency(invoice.saldo_devedor || invoice.nao_pago || 0) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button @click.stop="openDetails(invoice)" class="text-primary-600 dark:text-primary-400 hover:text-primary-800">
                                            Ver Detalhes
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="invoicesList.length === 0">
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        Nenhuma fatura em atraso encontrada.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Paginação -->
                <div class="mt-4 flex justify-between items-center" v-if="invoices && invoices.total > invoices.per_page">
                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        Página {{ currentPage }} de {{ totalPages }} (Total: {{ invoices.total }} faturas)
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

        <!-- Modal Detalhes -->
        <Modal :show="showModal" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    Detalhes da Fatura
                </h2>
                
                <div v-if="selectedInvoice" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <p class="text-xs uppercase text-gray-500 font-bold">Cliente</p>
                            <p class="font-medium text-lg dark:text-gray-200">{{ selectedInvoice.cliente_nome || selectedInvoice.cliente?.name || selectedInvoice.cliente?.nome }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ selectedInvoice.cliente?.cpf_cnpj || selectedInvoice.cliente?.documento || '-' }}</p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500">Vencimento</p>
                            <p class="font-medium dark:text-gray-200">{{ formatDate(selectedInvoice.data_vencimento) }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Emissão</p>
                            <p class="font-medium dark:text-gray-200">{{ formatDate(selectedInvoice.data_emissao || selectedInvoice.data_criacao?.split('T')[0]) }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Valor Original</p>
                            <p class="font-medium dark:text-gray-200">{{ formatCurrency(selectedInvoice.valor_original || selectedInvoice.total || 0) }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Saldo Devedor</p>
                            <p class="font-bold text-red-600 text-lg">{{ formatCurrency(selectedInvoice.saldo_devedor || selectedInvoice.nao_pago || 0) }}</p>
                        </div>
                         <div class="md:col-span-2">
                            <p class="text-sm text-gray-500">Descrição</p>
                            <p class="font-medium dark:text-gray-200">{{ selectedInvoice.descricao || '-' }}</p>
                        </div>
                        <div class="md:col-span-2 border-t pt-4 mt-2" v-if="selectedInvoice.link_boleto">
                             <a :href="selectedInvoice.link_boleto" target="_blank" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                Visualizar Boleto
                             </a>
                        </div>
                    </div>
                </div>

                <!-- Envio de WhatsApp -->
                <div class="mt-6 border-t pt-4" v-if="whatsappNumbers && whatsappNumbers.length > 0">
                    <h3 class="text-md font-medium text-gray-900 dark:text-gray-100 mb-2">Enviar Lembrete via WhatsApp</h3>
                    
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <InputLabel for="whatsapp_id" value="Enviar de" />
                            <select id="whatsapp_id" v-model="msgForm.whatsapp_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm">
                                <option v-for="wn in whatsappNumbers" :key="wn.id" :value="wn.id">
                                    {{ wn.description }} ({{ wn.ddi }}{{ wn.ddd }}{{ wn.phone }})
                                </option>
                            </select>
                            <div v-if="msgForm.errors.whatsapp_id" class="text-red-600 text-sm mt-1">{{ msgForm.errors.whatsapp_id }}</div>
                        </div>
                        <div>
                            <InputLabel for="to" value="Para (Celular)" />
                            <PhoneInput
                                id="to"
                                class="mt-1 block w-full"
                                v-model="msgForm.to"
                            />
                             <p class="text-sm text-gray-500 mt-1">Formato: (99) 99999-9999</p>
                            <InputError class="mt-2" :message="msgForm.errors.to" />
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
                        <div v-if="messageType === 'template' && templatesList.length > 0">
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
                            </select>
                        </div>

                        <div class="relative">
                            <div class="flex justify-between items-center mb-1">
                                <InputLabel for="message" value="Mensagem" />
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
                                v-model="msgForm.message" 
                                rows="6" 
                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm font-mono text-sm"
                            ></textarea>
                            <div v-if="msgForm.errors.message" class="text-red-600 text-sm mt-1">{{ msgForm.errors.message }}</div>
                        </div>
                        <div class="flex items-center justify-between">
                             <div class="text-sm">
                                <span v-if="msgForm.recentlySuccessful" class="text-green-600 font-medium">
                                    Mensagem enviada com sucesso!
                                </span>
                            </div>
                            <PrimaryButton @click="sendWhatsapp" :class="{ 'opacity-25': msgForm.processing }" :disabled="msgForm.processing">
                                Enviar Mensagem
                            </PrimaryButton>
                        </div>
                    </div>
                </div>
                <div v-else-if="whatsappNumbers && whatsappNumbers.length === 0" class="mt-6 border-t pt-4">
                     <p class="text-sm text-gray-500">Nenhum número de WhatsApp ativo configurado para envio.</p>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">
                        Fechar
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
