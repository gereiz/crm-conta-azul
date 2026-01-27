<script setup>
import Modal from '@/Components/Modal.vue';
import { computed } from 'vue';

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
    default: () => [],
  },
});

const percent = computed(() => {
  if (!props.total || props.total <= 0) return 0;
  const p = Math.round((props.progress / props.total) * 100);
  return Math.min(100, Math.max(0, p));
});
</script>

<template>
  <Modal :show="show" :closeable="false" maxWidth="2xl">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
        Sincronização em Lote — Progresso
      </h2>
      <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
        Empresas processadas: {{ progress }} de {{ total }} ({{ percent }}%)
      </p>

      <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700 mb-6">
        <div
          class="bg-blue-600 h-2.5 rounded-full transition-all"
          :style="{ width: percent + '%' }"
        />
      </div>

      <div class="space-y-2 max-h-72 overflow-auto">
        <div
          v-for="(log, idx) in logs"
          :key="idx"
          class="flex items-start gap-2 text-sm"
        >
          <span
            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
            :class="{
              'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200':
                log.status === 'syncing',
              'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200':
                log.status === 'success',
              'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200':
                log.status === 'error',
            }"
          >
            {{ log.status === 'syncing' ? 'Processando' : log.status === 'success' ? 'Sucesso' : 'Erro' }}
          </span>
          <span class="text-gray-700 dark:text-gray-300">{{ log.message }}</span>
        </div>
      </div>

      <div class="mt-6 text-xs text-gray-500 dark:text-gray-400">
        Esta janela fecha automaticamente ao concluir todas as empresas.
      </div>
    </div>
  </Modal>
</template>
