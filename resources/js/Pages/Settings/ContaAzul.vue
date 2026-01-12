<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    lastSync: String,
    totalClientes: Number,
    connections: Array,
});

const syncing = ref(false);
const syncingAll = ref(false);
const selectedConnectionId = ref(props.connections && props.connections.length ? props.connections[0].id : null);
const showingTokenModal = ref(false);
const accessToken = ref('');
const loadingToken = ref(false);
const showingSyncConfirmModal = ref(false);
const showingCreateModal = ref(false);
const showingEditModal = ref(false);
const createForm = ref({
    empresa_nome: '',
    email_desenvolvedor: '',
    ca_client_id: '',
    ca_client_secret: '',
    ca_redirect_uri: '',
    is_active: true,
});
const editForm = ref({
    id: null,
    empresa_nome: '',
    email_desenvolvedor: '',
    ca_client_id: '',
    ca_client_secret: '',
    ca_redirect_uri: '',
    is_active: true,
});
const selectedConnection = computed(() => {
    return props.connections?.find(c => c.id === selectedConnectionId.value) || null;
});

const syncClientes = (truncate = false) => {
    if (!selectedConnectionId.value) {
        alert('Selecione uma empresa para sincronizar.');
        return;
    }
    syncing.value = true;
    router.post(route('settings.contaazul.sync'), { connection_id: selectedConnectionId.value, truncate }, {
        onFinish: () => syncing.value = false,
    });
};

const showSyncConfirm = () => {
    if (!selectedConnectionId.value) {
        alert('Selecione uma empresa para sincronizar.');
        return;
    }
    if (props.connections && props.connections.length === 1) {
        showingSyncConfirmModal.value = true;
        return;
    }
    syncClientes(false);
};

const closeSyncConfirm = () => {
    showingSyncConfirmModal.value = false;
};

const syncAll = () => {
    if (!props.connections || !props.connections.length) {
        alert('Nenhuma empresa para sincronizar.');
        return;
    }
    syncingAll.value = true;
    router.post(route('settings.contaazul.syncAll'), {}, {
        onFinish: () => syncingAll.value = false,
    });
};

const showToken = () => {
    loadingToken.value = true;
    axios.get(route('settings.contaazul.token'), { params: { connection_id: selectedConnectionId.value } })
        .then(response => {
            accessToken.value = response.data.token;
            showingTokenModal.value = true;
        })
        .catch(error => {
            alert('Erro ao buscar token: ' + (error.response?.data?.error || error.message));
        })
        .finally(() => {
            loadingToken.value = false;
        });
};

const copyToken = () => {
    navigator.clipboard.writeText(accessToken.value);
    alert('Token copiado para a área de transferência!');
};

const closeModal = () => {
    showingTokenModal.value = false;
    accessToken.value = '';
};

const openCreate = () => {
    showingCreateModal.value = true;
};
const closeCreateModal = () => {
    showingCreateModal.value = false;
};
const submitCreate = () => {
    router.post(route('contaazul.connections.store'), createForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            showingCreateModal.value = false;
            createForm.value = { empresa_nome: '', email_desenvolvedor: '', ca_client_id: '', ca_client_secret: '', ca_redirect_uri: '', is_active: true };
        },
        onError: (errors) => {
            alert('Verifique os campos obrigatórios.');
        }
    });
};
const openEdit = () => {
    if (!selectedConnection.value) return;
    const c = selectedConnection.value;
    editForm.value = {
        id: c.id,
        empresa_nome: c.empresa_nome || '',
        email_desenvolvedor: c.email_desenvolvedor || '',
        ca_client_id: c.ca_client_id || '',
        ca_client_secret: '',
        ca_redirect_uri: c.ca_redirect_uri || '',
        is_active: !!c.is_active,
    };
    showingEditModal.value = true;
};
const closeEditModal = () => {
    showingEditModal.value = false;
};
const submitEdit = () => {
    router.put(route('contaazul.connections.update', { connection: editForm.value.id }), editForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            showingEditModal.value = false;
        },
        onError: () => {
            alert('Erro ao salvar conexão.');
        }
    });
};
const deleteConnection = (id) => {
    if (!confirm('Deseja remover esta conexão?')) return;
    router.delete(route('contaazul.connections.destroy', { connection: id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Configurações Conta Azul" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Configurações Conta Azul
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Card Sincronização -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-medium">Sincronização de Clientes</h3>
                            <a :href="selectedConnectionId ? route('contaazul.connections.connect', { connection: selectedConnectionId }) : route('contaazul.connect')" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-semibold hover:underline">
                                Reconectar Conta Azul
                            </a>
                        </div>
                        
                        <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    Total de clientes locais: <strong>{{ totalClientes }}</strong>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" v-if="lastSync">
                                    Última sincronização: {{ new Date(lastSync).toLocaleString() }}
                                </p>
                            </div>

                        <div class="flex items-center gap-3">
                            <select v-model="selectedConnectionId" class="text-sm border-gray-300 rounded-md">
                                <option v-for="c in connections" :key="c.id" :value="c.id">
                                    {{ c.empresa_nome }} (ID {{ c.id }})
                                </option>
                            </select>
                            <SecondaryButton @click="openEdit" :disabled="!selectedConnectionId">
                                Editar
                            </SecondaryButton>
                        </div>

                            <div class="flex items-center gap-3">
                                <button 
                                    @click="showSyncConfirm" 
                                    :disabled="syncing || !selectedConnectionId"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 active:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150 disabled:opacity-50"
                                >
                                    <svg v-if="syncing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ syncing ? 'Sincronizando...' : 'Sincronizar Agora' }}
                                </button>
                                <PrimaryButton @click="syncAll" :disabled="syncingAll">
                                    {{ syncingAll ? 'Sincronizando todas...' : 'Sincronizar Todas' }}
                                </PrimaryButton>
                            </div>
                        </div>

                        <div class="mt-4 text-sm text-gray-500">
                            <p>Esta ação irá buscar todos os clientes do Conta Azul e atualizar/criar no banco de dados local.</p>
                        </div>
                    </div>
                </div>

                <Modal :show="showingSyncConfirmModal" @close="closeSyncConfirm">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Sincronizar Clientes</h2>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Há apenas uma empresa cadastrada. Deseja deletar todos os clientes antes de sincronizar?</p>
                        <div class="mt-6 flex justify-end gap-3">
                            <SecondaryButton @click="() => { closeSyncConfirm(); syncClientes(false); }">Somente sincronizar</SecondaryButton>
                            <PrimaryButton @click="() => { closeSyncConfirm(); syncClientes(true); }">Deletar e sincronizar</PrimaryButton>
                        </div>
                    </div>
                </Modal>

                <!-- Card Desenvolvedor -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-medium mb-4">Ferramentas de Desenvolvedor</h3>
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Visualize o Token de Acesso atual para realizar testes manuais na API da Conta Azul.
                            </div>
                            <SecondaryButton @click="showToken" :disabled="loadingToken">
                                {{ loadingToken ? 'Carregando...' : 'Visualizar Token de Acesso' }}
                            </SecondaryButton>
                        </div>
                    </div>
                </div>

                <!-- Card Conexões -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium">Conexões Conta Azul</h3>
                            <PrimaryButton @click="openCreate">Nova Conexão</PrimaryButton>
                        </div>
                        <div class="space-y-3">
                            <div v-for="c in connections" :key="c.id" class="flex items-center justify-between p-3 rounded border border-gray-200 dark:border-gray-700">
                                <div>
                                    <div class="font-medium">{{ c.empresa_nome }}</div>
                                    <div class="text-xs text-gray-500">Redirect: {{ c.ca_redirect_uri }}</div>
                                    <div class="text-xs text-gray-500" v-if="c.token_expires_at">Token expira: {{ new Date(c.token_expires_at).toLocaleString() }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <SecondaryButton @click="selectedConnectionId = c.id; openEdit()">Editar</SecondaryButton>
                                    <SecondaryButton as="button" @click="deleteConnection(c.id)">Remover</SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Token -->
        <Modal :show="showingTokenModal" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    Token de Acesso (Bearer Token)
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Copie o token abaixo para usar na documentação da API. Este token é válido por pouco tempo e será renovado automaticamente pelo sistema se necessário.
                </p>

                <div class="mt-6">
                    <textarea 
                        readonly
                        class="w-full h-48 p-3 text-xs font-mono bg-gray-100 dark:bg-gray-900 border-gray-300 dark:border-gray-700 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        v-model="accessToken"
                    ></textarea>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeModal">
                        Fechar
                    </SecondaryButton>
                    <PrimaryButton @click="copyToken">
                        Copiar Token
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Modal Criar -->
        <Modal :show="showingCreateModal" @close="closeCreateModal">
            <div class="p-6 space-y-3">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Nova Conexão</h2>
                <TextInput v-model="createForm.empresa_nome" placeholder="Empresa" />
                <TextInput v-model="createForm.email_desenvolvedor" placeholder="E-mail desenvolvedor" />
                <TextInput v-model="createForm.ca_client_id" placeholder="Client ID" />
                <TextInput v-model="createForm.ca_client_secret" placeholder="Client Secret" />
                <TextInput v-model="createForm.ca_redirect_uri" placeholder="Redirect URI" />
                <div class="flex items-center gap-2">
                    <input type="checkbox" v-model="createForm.is_active" />
                    <span class="text-sm">Ativa</span>
                </div>
                <div class="mt-4 flex justify-end gap-3">
                    <SecondaryButton @click="closeCreateModal">Cancelar</SecondaryButton>
                    <PrimaryButton @click="submitCreate">Salvar</PrimaryButton>
                </div>
            </div>
        </Modal>

        <Modal :show="showingEditModal" @close="closeEditModal">
            <div class="p-6 space-y-3">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Editar Conexão</h2>
                <TextInput v-model="editForm.empresa_nome" placeholder="Empresa" />
                <TextInput v-model="editForm.email_desenvolvedor" placeholder="E-mail desenvolvedor" />
                <TextInput v-model="editForm.ca_client_id" placeholder="Client ID" />
                <TextInput v-model="editForm.ca_client_secret" placeholder="Client Secret" />
                <TextInput v-model="editForm.ca_redirect_uri" placeholder="Redirect URI" />
                <div class="flex items-center gap-2">
                    <input type="checkbox" v-model="editForm.is_active" />
                    <span class="text-sm">Ativa</span>
                </div>
                <div class="mt-4 flex justify-end gap-3">
                    <SecondaryButton @click="closeEditModal">Cancelar</SecondaryButton>
                    <PrimaryButton @click="submitEdit">Salvar</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
