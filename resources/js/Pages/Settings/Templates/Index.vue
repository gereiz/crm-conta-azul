<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    templates: {
        type: Array,
        required: true
    }
});

const showingModal = ref(false);
const editingTemplate = ref(null);
const emojiPickerOpen = ref(false);
const textareaRef = ref(null);

const form = useForm({
    name: '',
    content: '',
    is_default: false
});

const variables = [
    { code: '@@clientName@@', label: 'Nome do cliente' },
    { code: '@@clientCompany@@', label: 'Nome da empresa' },
    { code: '@@clientEmail@@', label: 'Email do cliente' },
    { code: '@@invoicePastDueQuantity@@', label: 'Qtd. cobranças vencidas' },
    { code: '@@invoicePastDueDates@@', label: 'Datas de vencimento' },
    { code: '@@invoiceTotalValue@@', label: 'Valor total vencido' }
];

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
    '🛡', '🔧', '🪛', '🔩', '⚙️', '🗜', '⚖️', '🦯', '🔗', '⛓', '🪝', '🧰', '🧲', '🪜', '🪞', '🪟', '🛏',
    '🛋', '🪑', '🚽', '🪠', '🚿', '🛁', '🧼', '🪒', '🧴', '🧷', '🧹', '🧺', '🧻', '🪣', '🧼', '🪥',
    '🧽', '🧯', '🛒', '🚬', '⚰️', '🪦', '⚱️', '🗿', '🪧', '✅', '✔️', '☑️', '❌', '❎', '➕', '➖',
    '➗', '➰', '➿', '〽️', '✳️', '✴️', '❇️', '‼️', '⁉️', '❓', '❔', '❕', '❗', '〰️', '©️', '®️',
    '™️', '#️⃣', '*️⃣', '0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟',
    '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⚫', '⚪', '🟤', '🔺', '🔻', '🔸', '🔹', '🔶', '🔷', '🔳',
    '🔲', '▪️', '▫️', '◾', '◽', '◼️', '◻️', '🟥', '🟧', '🟨', '🟩', '🟦', '🟪', '⬛', '⬜', '🟫',
];

const openModal = (template = null) => {
    editingTemplate.value = template;
    if (template) {
        form.name = template.name;
        form.content = template.content;
        form.is_default = !!template.is_default;
    } else {
        form.reset();
    }
    showingModal.value = true;
};

const closeModal = () => {
    showingModal.value = false;
    editingTemplate.value = null;
    form.reset();
    emojiPickerOpen.value = false;
};

const saveTemplate = () => {
    if (editingTemplate.value) {
        form.put(route('settings.templates.update', editingTemplate.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.templates.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteTemplate = (template) => {
    if (confirm('Tem certeza que deseja excluir este modelo?')) {
        router.delete(route('settings.templates.destroy', template.id));
    }
};

const insertVariable = (variable) => {
    const textarea = textareaRef.value;
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = form.content;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);

    form.content = before + variable + after;
    
    // Set cursor position after inserted variable
    setTimeout(() => {
        textarea.focus();
        textarea.setSelectionRange(start + variable.length, start + variable.length);
    }, 0);
};

const insertEmoji = (emoji) => {
    const textarea = textareaRef.value;
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = form.content;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);

    form.content = before + emoji + after;
    emojiPickerOpen.value = false;

    // Set cursor position after inserted emoji
    setTimeout(() => {
        textarea.focus();
        textarea.setSelectionRange(start + emoji.length, start + emoji.length);
    }, 0);
};

const toggleEmojiPicker = () => {
    emojiPickerOpen.value = !emojiPickerOpen.value;
};

</script>

<template>
    <Head title="Mensagens Padrão" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Mensagens Padrão
                </h2>
                <PrimaryButton @click="openModal()">
                    Nova Mensagem
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        
                        <div v-if="templates.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            Nenhum modelo de mensagem cadastrado.
                        </div>

                        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div v-for="template in templates" :key="template.id" class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 relative group hover:shadow-md transition-shadow bg-gray-50 dark:bg-gray-700/50">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-lg text-gray-800 dark:text-white truncate pr-8">{{ template.name }}</h3>
                                    <div class="flex items-center gap-2 absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="openModal(template)" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        </button>
                                        <button @click="deleteTemplate(template)" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </div>
                                </div>
                                
                                <span v-if="template.is_default" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 mb-3">
                                    Padrão
                                </span>

                                <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap line-clamp-4 font-mono bg-white dark:bg-gray-800 p-2 rounded border border-gray-200 dark:border-gray-600">
                                    {{ template.content }}
                                </p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Create/Edit -->
        <Modal :show="showingModal" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    {{ editingTemplate ? 'Editar Mensagem' : 'Nova Mensagem' }}
                </h2>

                <div class="mb-4">
                    <InputLabel for="name" value="Nome do Modelo" />
                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.name"
                        placeholder="Ex: Cobrança Padrão"
                        autofocus
                    />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>

                <div class="mb-4 relative">
                    <div class="flex justify-between items-center mb-1">
                        <InputLabel for="content" value="Conteúdo da Mensagem" />
                        <div class="flex items-center gap-2">
                            <!-- Emoji Trigger -->
                            <button @click="toggleEmojiPicker" type="button" class="text-gray-500 hover:text-yellow-500 transition-colors" title="Inserir Emoji">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Emoji Picker -->
                    <div v-if="emojiPickerOpen" class="absolute right-0 top-8 z-50 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg p-2 w-64 grid grid-cols-8 gap-1 max-h-48 overflow-y-auto custom-scrollbar">
                        <button v-for="emoji in emojis" :key="emoji" @click="insertEmoji(emoji)" type="button" class="hover:bg-gray-100 dark:hover:bg-gray-600 rounded p-1 text-lg">
                            {{ emoji }}
                        </button>
                    </div>

                    <textarea
                        id="content"
                        ref="textareaRef"
                        class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm h-48 font-mono text-sm"
                        v-model="form.content"
                        placeholder="Digite sua mensagem aqui..."
                    ></textarea>
                    <InputError :message="form.errors.content" class="mt-2" />

                    <!-- Variables Helper -->
                    <div class="mt-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Clique para inserir variáveis:</p>
                        <div class="flex flex-wrap gap-2">
                            <button 
                                v-for="variable in variables" 
                                :key="variable.code"
                                @click="insertVariable(variable.code)"
                                type="button"
                                class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 transition-colors border border-gray-200 dark:border-gray-600"
                                :title="variable.label"
                            >
                                {{ variable.code }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mb-6 flex items-center">
                    <input
                        id="is_default"
                        type="checkbox"
                        class="rounded dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800"
                        v-model="form.is_default"
                    />
                    <label for="is_default" class="ml-2 block text-sm text-gray-900 dark:text-gray-100">
                        Definir como mensagem padrão
                    </label>
                </div>

                <div class="flex justify-end gap-3">
                    <SecondaryButton @click="closeModal">
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton @click="saveTemplate" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                        {{ editingTemplate ? 'Salvar Alterações' : 'Criar Modelo' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: rgba(156, 163, 175, 0.5);
    border-radius: 20px;
}
</style>
