<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
  items: Object,
  connections: Array,
  filters: Object,
});

const form = ref({
  connection_id: props.filters?.connection_id || '',
  start_date: props.filters?.start_date || '',
  end_date: props.filters?.end_date || '',
  search: props.filters?.search || '',
  whatsapp_number_id: props.filters?.whatsapp_number_id || '',
  status: props.filters?.status || '',
  provider: props.filters?.provider || '',
  message_type: props.filters?.message_type || '',
  responded: props.filters?.responded || '',
  provider_message_id: props.filters?.provider_message_id || '',
});

const applyFilters = () => {
  router.get(route('whatsapp.returns.index'), form.value, { preserveState: true, preserveScroll: true });
};
const clearFilters = () => {
  Object.keys(form.value).forEach(k => form.value[k] = '');
  applyFilters();
};

const lastMsgs = ref({});
const loadingPhones = ref({});
const sanitizeDigits = (s) => (s || '').replace(/\D+/g, '');
const phoneKey = (row) => sanitizeDigits(row.phone_sanitized || row.phone_original);
const ensureLastMsgs = async (row) => {
  const key = phoneKey(row);
  if (!key) return;
  if (lastMsgs.value[key] || loadingPhones.value[key]) return;
  loadingPhones.value[key] = true;
  try {
    const url = route('whatsapp.returns.debug') + `?phone=${key}&limit=50`;
    const resp = await fetch(url);
    const json = await resp.json();
    const items = Array.isArray(json.items) ? json.items : [];
    const texts = [];
    for (const it of items) {
      const pj = it.payload_json || {};
      const body = (pj?.text && pj.text.body) || (pj?.message && pj.message.text && pj.message.text.body) || null;
      let ts = null;
      if (pj?.timestamp) {
        ts = new Date(Number(pj.timestamp) * 1000);
      } else if (pj?.context?.created_at) {
        ts = new Date(pj.context.created_at);
      } else if (it.created_at) {
        ts = new Date(it.created_at);
      }
      if (body) texts.push({ text: body, time: ts });
      if (texts.length >= 5) break;
    }
    lastMsgs.value[key] = texts;
  } catch (e) {
    lastMsgs.value[key] = [];
  } finally {
    loadingPhones.value[key] = false;
  }
};
const openPopover = ref(null);
const togglePopover = async (row) => {
  const key = phoneKey(row);
  if (!key) return;
  openPopover.value = openPopover.value === key ? null : key;
  if (openPopover.value === key) await ensureLastMsgs(row);
};
const copyMsg = async (msg) => {
  try { await navigator.clipboard.writeText(msg || ''); } catch (_e) {}
};
</script>

<template>
  <Head title="Retorno de Mensagens" />
  <AuthenticatedLayout>
    <template #header>
      <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Retorno de Mensagens</h2>
    </template>
    <div class="py-12">
      <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900 dark:text-gray-100">
            <h3 class="text-lg font-medium mb-4">Filtros</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="text-sm">Empresa</label>
                <select v-model="form.connection_id" class="w-full border-gray-300 rounded-md text-sm">
                  <option value="">Todas</option>
                  <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.empresa_nome }}</option>
                </select>
              </div>
              <div>
                <label class="text-sm">Data inicial</label>
                <input type="date" v-model="form.start_date" class="w-full border-gray-300 rounded-md text-sm">
              </div>
              <div>
                <label class="text-sm">Data final</label>
                <input type="date" v-model="form.end_date" class="w-full border-gray-300 rounded-md text-sm">
              </div>
              <div>
                <label class="text-sm">Cliente / Telefone</label>
                <input type="text" v-model="form.search" placeholder="Nome ou telefone" class="w-full border-gray-300 rounded-md text-sm">
              </div>
              <div>
                <label class="text-sm">Status</label>
                <select v-model="form.status" class="w-full border-gray-300 rounded-md text-sm">
                  <option value="">Todos</option>
                  <option value="PENDING">Pending</option>
                  <option value="SENT">Sent</option>
                  <option value="DELIVERED">Delivered</option>
                  <option value="READ">Read</option>
                  <option value="FAILED">Failed</option>
                  <option value="ERROR">Error</option>
                </select>
              </div>
              <div>
                <label class="text-sm">Respondidas</label>
                <select v-model="form.responded" class="w-full border-gray-300 rounded-md text-sm">
                  <option value="">Todas</option>
                  <option value="true">Sim</option>
                  <option value="false">Não</option>
                </select>
              </div>
              <div>
                <label class="text-sm">Provedor</label>
                <select v-model="form.provider" class="w-full border-gray-300 rounded-md text-sm">
                  <option value="">Todos</option>
                  <option value="whapi">Whapi</option>
                  <option value="evolution">Evolution</option>
                </select>
              </div>
              <div>
                <label class="text-sm">Tipo de envio</label>
                <select v-model="form.message_type" class="w-full border-gray-300 rounded-md text-sm">
                  <option value="">Todos</option>
                  <option value="billing">Cobrança</option>
                  <option value="due_date">Vencimento</option>
                  <option value="boleto">Emissão</option>
                  <option value="birthday">Aniversário</option>
                  <option value="manual">Manual</option>
                </select>
              </div>
              <div class="md:col-span-3">
                <label class="text-sm">ID da mensagem (provedor)</label>
                <input type="text" v-model="form.provider_message_id" class="w-full border-gray-300 rounded-md text-sm">
              </div>
            </div>
            <div class="mt-4 flex gap-3">
              <button @click="applyFilters" class="px-4 py-2 bg-blue-600 text-white rounded-md text-xs font-semibold">Filtrar</button>
              <button @click="clearFilters" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md text-xs font-semibold">Limpar</button>
            </div>
          </div>
        </div>

        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900 dark:text-gray-100">
            <h3 class="text-lg font-medium mb-4">Mensagens</h3>
            <div class="overflow-x-auto">
              <table class="min-w-full text-xs">
                <thead>
                  <tr class="text-left text-gray-600 dark:text-gray-300">
                    <th class="py-1">Data envio</th>
                    <th class="py-1">Empresa</th>
                    <th class="py-1">Número remetente</th>
                    <th class="py-1">Cliente</th>
                    <th class="py-1">Telefone</th>
                    <th class="py-1">Tipo</th>
                    <th class="py-1">Provedor</th>
                    <th class="py-1">ID provedor</th>
                    <th class="py-1">Status</th>
                    <th class="py-1">Respondida?</th>
                    <th class="py-1">Resposta</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in items.data" :key="row.id" class="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                    <td class="py-1">{{ row.sent_at ? new Date(row.sent_at).toLocaleString() : '' }}</td>
                    <td class="py-1">{{ row.connection?.empresa_nome || '' }}</td>
                    <td class="py-1">{{ row.whatsapp_number?.description || '' }}</td>
                    <td class="py-1">{{ row.client_name }}</td>
                    <td class="py-1">{{ row.phone_sanitized || row.phone_original }}</td>
                    <td class="py-1">{{ row.message_type }}</td>
                    <td class="py-1">{{ row.provider }}</td>
                    <td class="py-1">{{ row.provider_message_id }}</td>
                    <td class="py-1">
                      <span
                        :class="{
                          'px-2 py-0.5 rounded bg-gray-200 text-gray-800': row.delivery_status === 'PENDING',
                          'px-2 py-0.5 rounded bg-yellow-200 text-yellow-800': row.delivery_status === 'SENT',
                          'px-2 py-0.5 rounded bg-blue-200 text-blue-800': row.delivery_status === 'DELIVERED',
                          'px-2 py-0.5 rounded bg-green-200 text-green-800': row.delivery_status === 'READ',
                          'px-2 py-0.5 rounded bg-red-200 text-red-800': row.delivery_status === 'FAILED' || row.delivery_status === 'ERROR',
                          'px-2 py-0.5 rounded bg-gray-100 text-gray-600': !row.delivery_status,
                        }"
                        :title="`Atualizado: ${row.delivery_status_updated_at ? new Date(row.delivery_status_updated_at).toLocaleString() : 'N/A'} | Provedor: ${row.provider || 'N/A'}`"
                      >
                        {{ row.delivery_status || 'N/A' }}
                      </span>
                    </td>
                    <td class="py-1">
                      <span
                        :class="row.responded ? 'px-2 py-0.5 rounded bg-green-100 text-green-700' : 'px-2 py-0.5 rounded bg-gray-100 text-gray-600'"
                        :title="row.responded_at ? `Respondida em: ${new Date(row.responded_at).toLocaleString()}` : 'Sem resposta'"
                      >
                        {{ row.responded ? 'Sim' : 'Não' }}
                      </span>
                    </td>
                    <td class="py-1 relative">
                      <span
                        @click="togglePopover(row)"
                        :class="{
                          'px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 cursor-pointer': true
                        }"
                      >
                        Resposta
                      </span>
                      <div
                        v-if="openPopover === phoneKey(row)"
                        class="absolute z-10 mt-1 w-80 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded shadow-lg p-2"
                      >
                        <div v-if="loadingPhones[phoneKey(row)]" class="text-xs text-gray-500">Carregando…</div>
                        <div v-else-if="(lastMsgs[phoneKey(row)] || []).length === 0" class="text-xs text-gray-500">
                          {{ row.responded_at ? ('Respondida em: ' + new Date(row.responded_at).toLocaleString()) : 'Sem mensagens' }}
                        </div>
                        <ul v-else class="space-y-1">
                          <li
                            v-for="(m, idx) in lastMsgs[phoneKey(row)]"
                            :key="idx"
                            class="text-xs"
                          >
                            <button
                              @click="copyMsg(m.text)"
                              class="w-full text-left px-2 py-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                              style="white-space: pre-line"
                            >
                              <span class="font-semibold text-gray-700 dark:text-gray-200">{{ m.time ? new Date(m.time).toLocaleString() : '' }}</span>
                              <span class="ml-2 text-gray-800 dark:text-gray-100">{{ m.text }}</span>
                            </button>
                          </li>
                        </ul>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="mt-4 flex items-center gap-2">
              <button
                v-if="items.prev_page_url"
                @click="router.get(items.prev_page_url, {}, { preserveState: true, preserveScroll: true })"
                class="px-3 py-1 bg-gray-200 rounded text-xs"
              >
                Anterior
              </button>
              <span class="text-xs">Página {{ items.current_page }} de {{ items.last_page }}</span>
              <button
                v-if="items.next_page_url"
                @click="router.get(items.next_page_url, {}, { preserveState: true, preserveScroll: true })"
                class="px-3 py-1 bg-gray-200 rounded text-xs"
              >
                Próxima
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
