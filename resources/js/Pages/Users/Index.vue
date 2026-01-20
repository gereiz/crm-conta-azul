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
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    users: Array,
    roles: {
        type: Array,
        default: () => []
    }
});

const isModalOpen = ref(false);
const editingUser = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'operator',
    role_id: null,
    is_active: true,
});

const openModal = (user = null) => {
    editingUser.value = user;
    form.reset();
    form.clearErrors();
    
    if (user) {
        form.name = user.name;
        form.email = user.email;
        form.role = user.role;
        form.role_id = user.role_id ?? user.roleRef?.id ?? null;
        form.is_active = Boolean(user.is_active);
        // Password fields remain empty for security
    } else {
        form.role = 'operator';
        form.role_id = props.roles.find(r => (r.name || '').toLowerCase() === 'operador')?.id ?? null;
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    editingUser.value = null;
};

const submit = () => {
    if (editingUser.value) {
        form.put(route('users.update', editingUser.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteUser = (id) => {
    if (confirm('Tem certeza que deseja excluir este usuário?')) {
        router.delete(route('users.destroy', id));
    }
};

const roleLabel = (role) => {
    const roles = {
        'admin': 'Administrador',
        'operator': 'Operador',
        'viewer': 'Visualizador',
    };
    return roles[role] || role;
};

const findRoleNameById = (id) => {
    const r = props.roles.find(x => x.id === id);
    return r?.name || null;
};
</script>

<template>
    <Head title="Gestão de Usuários" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Gestão de Usuários
                </h2>
                <PrimaryButton @click="openModal()">
                    Novo Usuário
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nome</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Perfil</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="user in users" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ user.name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ user.email }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-primary-100 text-primary-800 dark:bg-primary-900/30 dark:text-primary-300">
                                            {{ user.roleRef?.name || roleLabel(user.role) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="{'bg-green-100 text-green-800': user.is_active, 'bg-red-100 text-red-800': !user.is_active}" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                                            {{ user.is_active ? 'Ativo' : 'Inativo' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button @click="openModal(user)" class="text-primary-600 hover:text-primary-800 mr-4">Editar</button>
                                        <button v-if="$page.props.auth.user.id !== user.id" @click="deleteUser(user.id)" class="text-red-600 hover:text-red-900">Excluir</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <Modal :show="isModalOpen" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ editingUser ? 'Editar Usuário' : 'Novo Usuário' }}
                </h2>

                <form @submit.prevent="submit" class="mt-6 space-y-4">
                    
                    <div>
                        <InputLabel for="name" value="Nome" />
                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.name"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <InputLabel for="role_id" value="Perfil" />
                        <select
                            id="role_id"
                            v-model="form.role_id"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-primary-500 dark:focus:border-primary-600 focus:ring-primary-500 dark:focus:ring-primary-600 rounded-md shadow-sm"
                        >
                            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.role_id" />
                    </div>

                    <div class="border-t pt-4 mt-4">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">
                            {{ editingUser ? 'Alterar Senha (Opcional)' : 'Definir Senha' }}
                        </h3>
                        
                        <div class="space-y-4">
                            <div>
                                <InputLabel for="password" value="Senha" />
                                <TextInput
                                    id="password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password"
                                    :required="!editingUser"
                                />
                                <InputError class="mt-2" :message="form.errors.password" />
                            </div>

                            <div>
                                <InputLabel for="password_confirmation" value="Confirmar Senha" />
                                <TextInput
                                    id="password_confirmation"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password_confirmation"
                                    :required="!editingUser"
                                />
                                <InputError class="mt-2" :message="form.errors.password_confirmation" />
                            </div>
                        </div>
                    </div>

                    <div class="block mt-4">
                        <label class="flex items-center">
                            <Checkbox name="is_active" v-model:checked="form.is_active" />
                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">Usuário Ativo</span>
                        </label>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <SecondaryButton @click="closeModal"> Cancelar </SecondaryButton>
                        <PrimaryButton class="ms-3" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            Salvar
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
