<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import BatchSyncModal from '@/Components/BatchSyncModal.vue';
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

const syncTarget = ref('invoices');
const showingReconnectModal = ref(false);
const reconnectMode = ref('auto'); // auto, manual
const reconnecting = ref(false);

const openReconnectModal = () => {
    if (!selectedConnection.value) return;
    showingReconnectModal.value = true;
    reconnectMode.value = 'auto';
};

const closeReconnectModal = () => {
    if (reconnecting.value) return;
    showingReconnectModal.value = false;
};

const confirmReconnect = async () => {
    if (!selectedConnection.value) return;

    if (reconnectMode.value === 'manual') {
        // Opção 1: Tenta abrir direto (funciona se não tiver sessão presa)
        const connectUrl = route('contaazul.connections.connect', { connection: selectedConnection.value.id });
        
        // Vamos oferecer uma UX melhor: copiar o link para anônima
        const userChoice = confirm(
            'Para garantir que você conecte a conta correta, recomendamos abrir o link em uma JANELA ANÔNIMA.\n\n' +
            'Clique em OK para abrir o link normalmente (pode pegar a conta errada se já estiver logado).\n' +
            'Clique em CANCELAR para copiar o link e abrir você mesmo na janela anônima.'
        );

        if (userChoice) {
            window.open(connectUrl, '_blank');
            reconnecting.value = false;
            showingReconnectModal.value = false;
        } else {
            // Copiar para área de transferência
            navigator.clipboard.writeText(connectUrl).then(() => {
                alert('Link copiado! Abra uma janela anônima (Ctrl+Shift+N) e cole o link na barra de endereços.');
            }).catch(() => {
                prompt('Copie o link abaixo e abra em uma janela anônima:', connectUrl);
            });
            reconnecting.value = false;
            showingReconnectModal.value = false;
        }
        
        return;
    }

    // Automatic
    reconnecting.value = true;
    try {
        const response = await axios.post(route('contaazul.connections.refresh', { connection: selectedConnection.value.id }));
        if (response.data.success) {
            alert('Token renovado com sucesso!');
            closeReconnectModal();
            router.reload();
        } else {
            alert('Falha ao renovar: ' + response.data.error);
        }
    } catch (e) {
        alert('Erro ao tentar renovar token: ' + (e.response?.data?.error || e.message));
    } finally {
        reconnecting.value = false;
    }
};

const syncClientes = (mode = 'update') => {
    if (!selectedConnectionId.value) {
        alert('Selecione uma empresa para sincronizar.');
        return;
    }
    syncing.value = true;
    router.post(route('settings.contaazul.sync'), { connection_id: selectedConnectionId.value, mode, target: syncTarget.value }, {
        onFinish: () => syncing.value = false,
    });
};

const showSyncConfirm = () => {
    if (!selectedConnectionId.value) {
        alert('Selecione uma empresa para sincronizar.');
        return;
    }
    if (props.connections && props.connections.length === 1 && syncTarget.value === 'all') {
        showingSyncConfirmModal.value = true;
        return;
    }
    syncClientes('update');
};

const closeSyncConfirm = () => {
    showingSyncConfirmModal.value = false;
};

const showingSyncAllModal = ref(false);
const syncAllMode = ref('update');
const syncBatchTarget = ref('invoices');
const syncQueue = ref([]);
const currentSyncIndex = ref(0);
const syncLogs = ref([]);
const currentSyncConnection = computed(() => {
    if (syncQueue.value.length > 0 && currentSyncIndex.value < syncQueue.value.length) {
        return syncQueue.value[currentSyncIndex.value];
    }
    return null;
});

const openSyncAllModal = () => {
    if (!props.connections || !props.connections.length) {
        alert('Nenhuma empresa para sincronizar.');
        return;
    }
    showingSyncAllModal.value = true;
    syncingAll.value = false;
    syncLogs.value = [];
};

const closeSyncAllModal = () => {
    if (syncingAll.value) return; // Não fecha se estiver rodando
    showingSyncAllModal.value = false;
};

const startSyncAll = async (mode) => {
    syncAllMode.value = mode;
    // Filtra apenas conexões ativas
    syncQueue.value = props.connections.filter(c => c.is_active);
    
    if (syncQueue.value.length === 0) {
        alert('Nenhuma conexão ativa encontrada.');
        return;
    }

    syncingAll.value = true;
    currentSyncIndex.value = 0;
    syncLogs.value = [];
    
    processNextSync();
};

const processNextSync = async () => {
    if (currentSyncIndex.value >= syncQueue.value.length) {
        syncLogs.value.push({ status: 'success', message: 'Sincronização de todas as empresas concluída!' });
        setTimeout(() => {
            syncingAll.value = false;
            router.reload();
        }, 2000);
        return;
    }

    const connection = syncQueue.value[currentSyncIndex.value];
    syncLogs.value.push({ status: 'syncing', message: `Sincronizando: ${connection.empresa_nome}...` });

    try {
        const response = await axios.post(route('settings.contaazul.sync'), { 
            connection_id: connection.id, 
            mode: syncAllMode.value,
            target: syncBatchTarget.value
        });
        
        if (response.data.success) {
            const clientes = response.data.details?.clientes_count || 0;
            const faturas = response.data.details?.invoices_count || 0;
            const msg = syncBatchTarget.value === 'all'
                ? `${connection.empresa_nome}: Sucesso (${clientes} clientes, ${faturas} faturas)`
                : `${connection.empresa_nome}: Sucesso (${faturas} faturas)`;
            syncLogs.value.push({ status: 'success', message: msg });
        } else {
            syncLogs.value.push({ 
                status: 'error', 
                message: `${connection.empresa_nome}: ${response.data.error || 'Erro desconhecido'}` 
            });
        }
    } catch (error) {
        const msg = error.response?.data?.error || error.message;
        syncLogs.value.push({ 
            status: 'error', 
            message: `${connection.empresa_nome}: Falha - ${msg}` 
        });
    } finally {
        currentSyncIndex.value++;
        processNextSync();
    }
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
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8 space-y-6">
                <!-- Card Sincronização -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-medium">Sincronização de Clientes</h3>
                            <button 
                                v-if="selectedConnectionId"
                                @click="openReconnectModal" 
                                class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-semibold hover:underline"
                            >
                                Reconectar Conta Azul
                            </button>
                            <a v-else :href="route('contaazul.connect')" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-semibold hover:underline">
                                Conectar Conta Azul (Legado)
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
                                <select v-model="syncTarget" class="text-sm border-gray-300 rounded-md">
                                    <option value="invoices">Apenas faturas</option>
                                    <option value="clients">Apenas clientes</option>
                                    <option value="all">Clientes + faturas</option>
                                </select>
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
                                <PrimaryButton @click="openSyncAllModal" :disabled="syncingAll">
                                    Sincronizar Todas
                                </PrimaryButton>
                            </div>
                        </div>

                        <div class="mt-4 text-sm text-gray-500">
                            <p v-if="syncTarget === 'all'">Esta ação irá buscar todos os clientes do Conta Azul e atualizar/criar no banco de dados local, além de sincronizar faturas.</p>
                            <p v-else-if="syncTarget === 'clients'">Esta ação irá sincronizar apenas os clientes desta empresa.</p>
                            <p v-else>Esta ação irá sincronizar apenas as faturas (abertas/atrasadas e atualizações recentes).</p>
                        </div>

                        <!-- Aviso de política de sincronização -->
                        <div class="mt-4 p-4 rounded-md border bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-sm text-blue-800 dark:text-blue-200">
                            <p class="font-semibold">Política de Sincronização</p>
                            <ul class="list-disc list-inside mt-1">
                                <li>01:00 — sincronização automática diária de <strong>Clientes + Faturas</strong> para todas as empresas ativas.</li>
                                <li>08:45 — sincronização automática diária de <strong>apenas Clientes</strong>.</li>
                                <li>10:00 — sincronização automática diária de <strong>apenas Faturas</strong>.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Modal Confirmação Single -->
                <Modal :show="showingSyncConfirmModal" @close="closeSyncConfirm">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Sincronização</h2>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Há apenas uma empresa cadastrada. Como deseja sincronizar?</p>
                        <div class="mt-4">
                            <select v-model="syncTarget" class="text-sm border-gray-300 rounded-md w-full">
                                <option value="invoices">Apenas faturas</option>
                                <option value="clients">Apenas clientes</option>
                                <option value="all">Clientes + faturas</option>
                            </select>
                        </div>
                        <div class="mt-6 flex justify-end gap-3">
                            <SecondaryButton @click="() => { closeSyncConfirm(); syncClientes('update'); }">Atualizar Diferenças</SecondaryButton>
                            <PrimaryButton @click="() => { closeSyncConfirm(); syncClientes('reset'); }">Resetar e Sincronizar</PrimaryButton>
                        </div>
                    </div>
                </Modal>

                <!-- Modal Sincronização em Lote -->
                <Modal :show="showingSyncAllModal" @close="closeSyncAllModal">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Sincronização em Lote
                        </h2>

                        <div v-if="!syncingAll">
                            <p class="text-sm text-gray-600 dark:text-gray-300 mb-6">
                                Você está prestes a sincronizar <strong>{{ props.connections.filter(c => c.is_active).length }}</strong> empresas ativas.
                                Como deseja proceder?
                            </p>
                            
                            <div class="mb-6">
                                <label class="text-sm text-gray-700 dark:text-gray-300">Alvo da sincronização</label>
                                <select v-model="syncBatchTarget" class="mt-1 text-sm border-gray-300 rounded-md w-full">
                                    <option value="invoices">Apenas faturas</option>
                                    <option value="clients">Apenas clientes</option>
                                    <option value="all">Clientes + faturas</option>
                                </select>
                            </div>
                            
                            <div class="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-md mb-6 border border-yellow-200 dark:border-yellow-800">
                                <h4 class="font-bold text-yellow-800 dark:text-yellow-200 text-sm mb-2">Opções:</h4>
                                <ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300 space-y-1">
                                    <li><strong>Atualizar Diferenças:</strong> Atualiza registros existentes, cria novos e remove apenas os que não existem mais na Conta Azul (Pruning). Mais seguro.</li>
                                    <li><strong>Resetar e Sincronizar:</strong> APAGA TODOS os clientes e faturas locais das empresas e baixa tudo novamente. Use apenas se houver inconsistências graves.</li>
                                </ul>
                            </div>

                            <div class="flex justify-end gap-3">
                                <SecondaryButton @click="closeSyncAllModal">Cancelar</SecondaryButton>
                                <button 
                                    @click="startSyncAll('update')"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 active:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150"
                                >
                                    Atualizar Diferenças
                                </button>
                                <PrimaryButton @click="startSyncAll('reset')" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                                    Resetar e Sincronizar
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>
                </Modal>

                <!-- Novo Componente de Progresso -->
                <BatchSyncModal 
                    :show="syncingAll"
                    :progress="currentSyncIndex"
                    :total="syncQueue.length"
                    :logs="syncLogs"
                />

                <!-- Modal Reconexão -->
                <Modal :show="showingReconnectModal" @close="closeReconnectModal">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Reconectar Conta Azul - {{ selectedConnection?.empresa_nome }}
                        </h2>

                        <p class="text-sm text-gray-600 dark:text-gray-300 mb-6">
                            Como deseja reconectar esta empresa?
                        </p>

                        <div class="space-y-4 mb-6">
                            <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700" :class="{'border-blue-500 bg-blue-50 dark:bg-blue-900/20': reconnectMode === 'auto'}">
                                <input type="radio" v-model="reconnectMode" value="auto" class="mr-3" />
                                <div>
                                    <div class="font-bold text-sm">Renovação Automática (Recomendado)</div>
                                    <div class="text-xs text-gray-500">Tenta renovar o token atual sem sair do sistema.</div>
                                </div>
                            </label>

                            <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700" :class="{'border-blue-500 bg-blue-50 dark:bg-blue-900/20': reconnectMode === 'manual'}">
                                <input type="radio" v-model="reconnectMode" value="manual" class="mr-3" />
                                <div>
                                    <div class="font-bold text-sm">Reconexão Manual</div>
                                    <div class="text-xs text-gray-500">Redireciona para o login do Conta Azul para gerar um novo token.</div>
                                </div>
                            </label>
                        </div>

                        <div class="flex justify-end gap-3">
                            <SecondaryButton @click="closeReconnectModal" :disabled="reconnecting">Cancelar</SecondaryButton>
                            <PrimaryButton @click="confirmReconnect" :disabled="reconnecting">
                                {{ reconnecting ? 'Processando...' : 'Confirmar' }}
                            </PrimaryButton>
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
