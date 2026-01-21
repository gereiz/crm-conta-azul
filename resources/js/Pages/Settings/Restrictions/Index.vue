<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import axios from 'axios';

const props = defineProps({
    restrictions: Object,
    connections: Array,
});

const list = computed(() => {
    return Array.isArray(props.restrictions?.data) ? props.restrictions.data : (props.restrictions || []);
});

const showCreate = ref(false);
const showEdit = ref(false);
const editing = ref(null);

const createForm = useForm({
    connection_id: '',
    type: 'client_equals',
    value: '',
    is_active: true,
});

const editForm = useForm({
    id: null,
    connection_id: '',
    type: 'client_equals',
    value: '',
    is_active: true,
});

const typeLabel = (t) => {
    if (t === 'client_equals') return 'Cliente é igual a';
    if (t === 'invoice_equals') return 'Fatura é igual a';
    if (t === 'description_contains') return 'Descrição contém';
    return t;
};

const statusLabel = (s) => s ? 'Ativo' : 'Inativo';

const openCreate = () => {
    createForm.reset();
    createForm.type = 'client_equals';
    createForm.is_active = true;
    showCreate.value = true;
};

const openEdit = (r) => {
    editing.value = r;
    editForm.id = r.id;
    editForm.connection_id = r.connection_id;
    editForm.type = r.type;
    editForm.value = r.value;
    editForm.is_active = !!r.is_active;
    showEdit.value = true;
};

const submitCreate = () => {
    router.post(route('settings.restrictions.store'), createForm, {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
};

const submitEdit = () => {
    if (!editing.value) return;
    router.put(route('settings.restrictions.update', editing.value.id), {
        connection_id: editForm.connection_id,
        type: editForm.type,
        value: editForm.value,
        is_active: editForm.is_active,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showEdit.value = false;
            editing.value = null;
        },
    });
};

const toggleStatus = (r) => {
    router.post(route('settings.restrictions.toggle', r.id), {}, { preserveScroll: true });
};

const destroyRestriction = (r) => {
    if (!confirm('Deseja excluir esta regra?')) return;
    router.delete(route('settings.restrictions.destroy', r.id), { preserveScroll: true });
};

const clientSuggestions = ref([]);
const invoiceSuggestions = ref([]);
const loadingSuggest = ref(false);
const suggestQuery = ref('');

const fetchClientSuggestions = async (connectionId, q) => {
    if (!connectionId) { clientSuggestions.value = []; return; }
    loadingSuggest.value = true;
    try {
        const res = await axios.get(route('settings.restrictions.autocomplete.clients'), { params: { connection_id: connectionId, query: q || '' } });
        clientSuggestions.value = res.data || [];
    } catch {
        clientSuggestions.value = [];
    } finally {
        loadingSuggest.value = false;
    }
};

const fetchInvoiceSuggestions = async (connectionId, q) => {
    if (!connectionId) { invoiceSuggestions.value = []; return; }
    loadingSuggest.value = true;
    try {
        const res = await axios.get(route('settings.restrictions.autocomplete.invoices'), { params: { connection_id: connectionId, query: q || '' } });
        invoiceSuggestions.value = res.data || [];
    } catch {
        invoiceSuggestions.value = [];
    } finally {
        loadingSuggest.value = false;
    }
};

watch(() => createForm.type, () => {
    suggestQuery.value = '';
    clientSuggestions.value = [];
    invoiceSuggestions.value = [];
    createForm.value = '';
});
watch(() => editForm.type, () => {
    suggestQuery.value = '';
    clientSuggestions.value = [];
    invoiceSuggestions.value = [];
    editForm.value = '';
});
watch(() => createForm.connection_id, () => {
    suggestQuery.value = '';
    clientSuggestions.value = [];
    invoiceSuggestions.value = [];
});
watch(() => editForm.connection_id, () => {
    suggestQuery.value = '';
    clientSuggestions.value = [];
    invoiceSuggestions.value = [];
});
</script>

<template>
    <Head title="Restrições e Envio" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Restrições e Envio
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Regras</h3>
                            <PrimaryButton @click="openCreate">Nova Regra</PrimaryButton>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Empresa</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tipo</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Valor</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Criada em</th>
                                        <th class="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="r in list" :key="r.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ r.connection?.empresa_nome || 'Empresa' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                                  :class="r.type === 'description_contains' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : r.type === 'invoice_equals' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'">
                                                {{ typeLabel(r.type) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ r.value }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                                  :class="r.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700/30 dark:text-gray-300'">
                                                {{ statusLabel(r.is_active) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ new Date(r.created_at).toLocaleString() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button @click="openEdit(r)" class="text-primary-600 dark:text-primary-400 hover:text-primary-800 mr-3">Editar</button>
                                            <button @click="toggleStatus(r)" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 mr-3">{{ r.is_active ? 'Desativar' : 'Ativar' }}</button>
                                            <button @click="destroyRestriction(r)" class="text-red-600 dark:text-red-400 hover:text-red-800">Excluir</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="props.restrictions?.total > props.restrictions?.per_page" class="mt-4 flex justify-between items-center">
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                Página {{ props.restrictions.current_page }} de {{ Math.ceil(props.restrictions.total / props.restrictions.per_page) }} (Total: {{ props.restrictions.total }})
                            </div>
                            <div class="flex gap-2">
                                <SecondaryButton 
                                    :disabled="props.restrictions.current_page <= 1" 
                                    @click="router.get(route('settings.restrictions.index', { page: props.restrictions.current_page - 1 }))"
                                    class="px-3 py-1"
                                    :class="{ 'opacity-50 cursor-not-allowed': props.restrictions.current_page <= 1 }"
                                >
                                    Anterior
                                </SecondaryButton>
                                <SecondaryButton 
                                    :disabled="props.restrictions.current_page >= Math.ceil(props.restrictions.total / props.restrictions.per_page)" 
                                    @click="router.get(route('settings.restrictions.index', { page: props.restrictions.current_page + 1 }))"
                                    class="px-3 py-1"
                                    :class="{ 'opacity-50 cursor-not-allowed': props.restrictions.current_page >= Math.ceil(props.restrictions.total / props.restrictions.per_page) }"
                                >
                                    Próxima
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showCreate" @close="showCreate = false">
            <div class="p-6 space-y-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Nova Regra</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Empresa</label>
                    <select v-model="createForm.connection_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                        <option value="">Selecione</option>
                        <option v-for="c in props.connections" :key="c.id" :value="c.id">{{ c.empresa_nome }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo</label>
                    <select v-model="createForm.type" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                        <option value="client_equals">Cliente é igual a</option>
                        <option value="invoice_equals">Fatura é igual a</option>
                        <option value="description_contains">Descrição contém</option>
                    </select>
                </div>
                <div v-if="createForm.type !== 'description_contains'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Valor</label>
                    <div class="relative">
                        <TextInput v-model="suggestQuery" placeholder="Digite para buscar" class="w-full" @input="createForm.value=''; createForm.type==='client_equals' ? fetchClientSuggestions(createForm.connection_id, suggestQuery) : fetchInvoiceSuggestions(createForm.connection_id, suggestQuery)" />
                        <div v-if="loadingSuggest" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">...</div>
                    </div>
                    <div v-if="createForm.type === 'client_equals' && clientSuggestions.length" class="mt-2 border border-gray-200 dark:border-gray-700 rounded-md max-h-40 overflow-auto">
                        <button v-for="c in clientSuggestions" :key="c.id" type="button" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700" @click="createForm.value = c.name || c.company_name">
                            {{ c.name || c.company_name }}
                        </button>
                    </div>
                    <div v-if="createForm.type === 'invoice_equals' && invoiceSuggestions.length" class="mt-2 border border-gray-200 dark:border-gray-700 rounded-md max-h-40 overflow-auto">
                        <button v-for="i in invoiceSuggestions" :key="i.id" type="button" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700" @click="createForm.value = i.ca_id">
                            {{ i.ca_id }} — {{ i.descricao }}
                        </button>
                    </div>
                </div>
                <div v-else>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Valor</label>
                    <TextInput v-model="createForm.value" placeholder="Texto da descrição" class="w-full" />
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" v-model="createForm.is_active" />
                    <span class="text-sm">Ativo</span>
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton @click="showCreate = false">Cancelar</SecondaryButton>
                    <PrimaryButton @click="submitCreate">Salvar</PrimaryButton>
                </div>
            </div>
        </Modal>

        <Modal :show="showEdit" @close="showEdit = false">
            <div class="p-6 space-y-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Editar Regra</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Empresa</label>
                    <select v-model="editForm.connection_id" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                        <option value="">Selecione</option>
                        <option v-for="c in props.connections" :key="c.id" :value="c.id">{{ c.empresa_nome }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo</label>
                    <select v-model="editForm.type" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                        <option value="client_equals">Cliente é igual a</option>
                        <option value="invoice_equals">Fatura é igual a</option>
                        <option value="description_contains">Descrição contém</option>
                    </select>
                </div>
                <div v-if="editForm.type !== 'description_contains'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Valor</label>
                    <div class="relative">
                        <TextInput v-model="suggestQuery" placeholder="Digite para buscar" class="w-full" @input="editForm.value=''; editForm.type==='client_equals' ? fetchClientSuggestions(editForm.connection_id, suggestQuery) : fetchInvoiceSuggestions(editForm.connection_id, suggestQuery)" />
                        <div v-if="loadingSuggest" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">...</div>
                    </div>
                    <div v-if="editForm.type === 'client_equals' && clientSuggestions.length" class="mt-2 border border-gray-200 dark:border-gray-700 rounded-md max-h-40 overflow-auto">
                        <button v-for="c in clientSuggestions" :key="c.id" type="button" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700" @click="editForm.value = c.name || c.company_name">
                            {{ c.name || c.company_name }}
                        </button>
                    </div>
                    <div v-if="editForm.type === 'invoice_equals' && invoiceSuggestions.length" class="mt-2 border border-gray-200 dark:border-gray-700 rounded-md max-h-40 overflow-auto">
                        <button v-for="i in invoiceSuggestions" :key="i.id" type="button" class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700" @click="editForm.value = i.ca_id">
                            {{ i.ca_id }} — {{ i.descricao }}
                        </button>
                    </div>
                </div>
                <div v-else>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Valor</label>
                    <TextInput v-model="editForm.value" placeholder="Texto da descrição" class="w-full" />
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" v-model="editForm.is_active" />
                    <span class="text-sm">Ativo</span>
                </div>
                <div class="flex justify-end gap-3">
                    <SecondaryButton @click="showEdit = false">Cancelar</SecondaryButton>
                    <PrimaryButton @click="submitEdit">Salvar</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
