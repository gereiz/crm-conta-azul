<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    progress: {
        type: Number,
        default: 0,
    },
    total: {
        type: Number,
        default: 0,
    },
    logs: {
        type: Array,
        default: () => [], // Ex: [{ message: '...', status: 'syncing'|'success'|'error' }]
    },
});

const progressPercentage = computed(() => {
    if (props.total === 0) return 0;
    return Math.min(100, Math.round((props.progress / props.total) * 100));
});

const logContainer = ref(null);

// Auto-scroll para o final quando novos logs chegam
watch(() => props.logs.length, () => {
    nextTick(() => {
        if (logContainer.value) {
            logContainer.value.scrollTop = logContainer.value.scrollHeight;
        }
    });
});
</script>

<template>
    <Modal :show="show" maxWidth="2xl" :closeable="false">
        <div class="p-6 bg-white dark:bg-gray-800 rounded-lg">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">
                Sincronização em Lote
            </h2>

            <!-- Barra de Progresso -->
            <div class="mb-6">
                <div class="flex justify-between items-center mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span>Progresso Geral</span>
                    <span>{{ progress }} / {{ total }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3 dark:bg-gray-700 overflow-hidden">
                    <div 
                        class="bg-blue-600 h-full rounded-full transition-all duration-500 ease-out" 
                        :style="{ width: `${progressPercentage}%` }"
                    ></div>
                </div>
            </div>

            <!-- Janela de Log (Terminal) -->
            <div 
                ref="logContainer"
                class="bg-[#0f172a] rounded-md p-4 h-80 overflow-y-auto font-mono text-sm shadow-inner mb-4 border border-gray-700"
            >
                <div v-for="(log, index) in logs" :key="index" class="mb-2 last:mb-0">
                    <!-- Estado: Sincronizando -->
                    <div v-if="log.status === 'syncing'" class="flex items-start text-blue-400">
                        <span class="mr-2 mt-0.5 animate-spin">
                            <!-- Ícone Loading Spinner -->
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                        <span>{{ log.message }}</span>
                    </div>

                    <!-- Estado: Sucesso -->
                    <div v-else-if="log.status === 'success'" class="flex items-start text-green-400">
                        <span class="mr-2 mt-0.5">
                             <!-- Ícone Check -->
                             <div class="bg-green-500 rounded-sm flex items-center justify-center w-4 h-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                             </div>
                        </span>
                        <span>{{ log.message }}</span>
                    </div>

                    <!-- Estado: Erro -->
                    <div v-else-if="log.status === 'error'" class="flex items-start text-red-400">
                        <span class="mr-2 mt-0.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        <span>{{ log.message }}</span>
                    </div>
                    
                    <!-- Estado Padrão -->
                    <div v-else class="text-gray-400">
                        {{ log.message }}
                    </div>
                </div>
                <!-- Cursor Piscando -->
                <div class="text-green-500 animate-pulse mt-1">_</div>
            </div>

            <!-- Rodapé -->
            <div class="text-center text-sm text-gray-500 dark:text-gray-400">
                Por favor, não feche esta janela...
            </div>
        </div>
    </Modal>
</template>
