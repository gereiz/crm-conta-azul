<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import Toggle from '@/Components/Toggle.vue';

const props = defineProps({
    numbers: Array,
});

const isModalOpen = ref(false);
const editingNumber = ref(null);

const form = useForm({
    description: '',
    ddi: '55',
    ddd: '',
    phone: '',
    whapi_key: '',
    is_default: false,
    status: 'active',
    use_poli: false,
    poli_key: '',
    poli_customer: '',
    poli_channel: '',
    poli_user: '',
    poli_template: '',
});

const formatPhone = (value) => {
    if (!value) return '';
    const cleaned = value.replace(/\D/g, '');
    if (cleaned.length === 9) {
        return cleaned.replace(/(\d{5})(\d{4})/, '$1-$2');
    }
    if (cleaned.length === 8) {
        return cleaned.replace(/(\d{4})(\d{4})/, '$1-$2');
    }
    return value;
};

const handlePhoneInput = (value) => {
    let cleaned = value.replace(/\D/g, '');
    if (cleaned.length > 9) cleaned = cleaned.slice(0, 9);
    
    let formatted = cleaned;
    if (cleaned.length > 5) {
        formatted = cleaned.replace(/^(\d{5})(\d{0,4})/, '$1-$2');
    } else if (cleaned.length > 4 && cleaned.length === 8) {
         // This block handles 8 digits if needed, but the above regex is greedy for 5 digits.
         // If we want to support 4-4 for 8 digits:
         // But we can't know if it's 8 or 9 until user finishes typing.
         // Standard Brazil mobile is 9.
    }
    
    form.phone = formatted;
};

const openModal = (number = null) => {
    editingNumber.value = number;
    if (number) {
        form.description = number.description;
        form.ddi = number.ddi;
        form.ddd = number.ddd;
        form.phone = formatPhone(number.phone);
        form.whapi_key = number.whapi_key || '';
        form.is_default = Boolean(number.is_default);
        form.status = number.status;
        form.use_poli = Boolean(number.use_poli);
        form.poli_key = number.poli_key || '';
        form.poli_customer = number.poli_customer || '';
        form.poli_channel = number.poli_channel || '';
        form.poli_user = number.poli_user || '';
        form.poli_template = number.poli_template || '';
    } else {
        form.reset();
        form.ddi = '55';
        form.status = 'active';
        form.use_poli = false;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    editingNumber.value = null;
};

const submit = () => {
    const transformData = (data) => ({
        ...data,
        phone: data.phone.replace(/\D/g, ''),
    });

    if (editingNumber.value) {
        form.transform(transformData).put(route('whatsapp.update', editingNumber.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.transform(transformData).post(route('whatsapp.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteNumber = (id) => {
    if (confirm('Tem certeza que deseja excluir este número?')) {
        router.delete(route('whatsapp.destroy', id));
    }
};
</script>

<template>
    <Head title="WhatsApp Config" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Configurações de WhatsApp
                </h2>
                <PrimaryButton @click="openModal()">
                    Adicionar Número
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Descrição</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Número</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Plataforma</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Padrão</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="number in numbers" :key="number.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ number.description || '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        +{{ number.ddi }} ({{ number.ddd }}) {{ formatPhone(number.phone) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="number.use_poli" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300">
                                            Poli
                                        </span>
                                        <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300">
                                            Whapi
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="{'bg-green-100 text-green-800': number.status === 'active', 'bg-red-100 text-red-800': number.status === 'inactive'}" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ number.status === 'active' ? 'Ativo' : 'Inativo' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <span v-if="number.is_default" class="text-blue-600 font-bold">Sim</span>
                                        <span v-else>Não</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button @click="openModal(number)" class="text-primary-600 hover:text-primary-800 mr-4">Editar</button>
                                        <button @click="deleteNumber(number.id)" class="text-red-600 hover:text-red-900">Excluir</button>
                                    </td>
                                </tr>
                                <tr v-if="numbers.length === 0">
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        Nenhum número configurado.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de Cadastro/Edição -->
        <Modal :show="isModalOpen" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    {{ editingNumber ? 'Editar Número' : 'Adicionar Novo Número' }}
                </h2>

                <form @submit.prevent="submit" class="space-y-4">
                    
                    <div>
                        <InputLabel for="description" value="Descrição (Opcional)" />
                        <TextInput
                            id="description"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.description"
                            placeholder="Ex: Comercial, Suporte"
                        />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-1">
                            <InputLabel for="ddi" value="DDI *" />
                            <TextInput
                                id="ddi"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.ddi"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.ddi" />
                        </div>
                        <div class="col-span-1">
                            <InputLabel for="ddd" value="DDD *" />
                            <TextInput
                                id="ddd"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.ddd"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.ddd" />
                        </div>
                         <div class="col-span-1">
                            <InputLabel for="phone" value="Telefone *" />
                            <TextInput
                                id="phone"
                                type="text"
                                class="mt-1 block w-full"
                                :model-value="form.phone"
                                @update:model-value="handlePhoneInput"
                                required
                                placeholder="xxxxx-xxxx"
                            />
                            <InputError class="mt-2" :message="form.errors.phone" />
                        </div>
                    </div>

                <div>
                        <InputLabel for="whapi_key" value="Whapi Key" />
                        <TextInput
                            id="whapi_key"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.whapi_key"
                        />
                        <InputError class="mt-2" :message="form.errors.whapi_key" />
                    </div>

                    <div class="space-y-2">
                        <Toggle 
                            :model-value="form.status === 'active'" 
                            @update:model-value="val => form.status = val ? 'active' : 'inactive'" 
                            label="Número Ativo" 
                        />
                        
                        <Toggle v-model="form.is_default" label="Definir como Padrão" />
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="closeModal"> Cancelar </SecondaryButton>
                        <PrimaryButton class="ms-3" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            {{ editingNumber ? 'Atualizar' : 'Salvar' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
