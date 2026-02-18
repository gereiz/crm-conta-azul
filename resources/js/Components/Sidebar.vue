<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NavLink from '@/Components/NavLink.vue';
import { ref, onMounted, watch } from 'vue';
import { applyTheme } from '@/Utils/theme';

defineProps({
    open: {
        type: Boolean,
        default: true,
    }
});

const showingSettings = ref(false);
const showingClientes = ref(false);
const showingWhatsapp = ref(false);
const darkMode = ref(false);
const page = usePage();

const toggleDark = () => {
    darkMode.value = !darkMode.value;
    const root = document.documentElement;
    if (darkMode.value) {
        root.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        root.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
    
    // Reapply theme to ensure correct shades
    const settings = page.props.system_settings;
    if (settings) {
        applyTheme(settings.primary_color, settings.secondary_color);
    }
};

onMounted(() => {
    if (route().current('settings.*') || route().current('profile.*')) {
        showingSettings.value = true;
    }
    if (route().current('clientes.*')) {
        showingClientes.value = true;
    }
    if (route().current('whatsapp.*')) {
        showingWhatsapp.value = true;
    }

    // Initialize Dark Mode
    const stored = localStorage.getItem('theme');
    if (stored === 'dark') {
        darkMode.value = true;
        document.documentElement.classList.add('dark');
    } else {
        darkMode.value = false;
        document.documentElement.classList.remove('dark');
    }

    // Apply theme colors
    const settings = page.props.system_settings;
    if (settings) {
        applyTheme(settings.primary_color, settings.secondary_color);
    }
});

watch(() => page.props.system_settings, (newSettings) => {
    if (newSettings) {
        applyTheme(newSettings.primary_color, newSettings.secondary_color);
    }
}, { deep: true });
</script>

<template>
    <aside :class="['bg-white dark:bg-gray-800 border-r border-gray-100 dark:border-gray-700 min-h-screen transition-all duration-300 flex flex-col', open ? 'w-64' : 'w-20']">
        <!-- Logo Area -->
        <div class="h-16 flex items-center justify-center border-b border-gray-100 dark:border-gray-700 shrink-0">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
                <ApplicationLogo class="block h-12 w-auto fill-current text-primary-600 dark:text-primary-400" />
                <span v-if="open && page.props.system_settings?.system_name" class="font-bold text-xl text-gray-800 dark:text-white tracking-tight">
                    {{ page.props.system_settings.system_name }}
                </span>
            </Link>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 mt-6 px-3 space-y-2 overflow-y-auto custom-scrollbar">
            <p v-if="open" class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Menu Principal</p>

            <NavLink :href="route('dashboard')" :active="route().current('dashboard')" class="group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-200" :class="route().current('dashboard') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'">
                <svg class="w-5 h-5 mr-3 transition-colors duration-200" :class="route().current('dashboard') ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                <span v-if="open">Dashboard</span>
                <div v-if="!open && route().current('dashboard')" class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-primary-600 rounded-r-full"></div>
            </NavLink>

            <!-- Clientes Submenu -->
            <div>
                <button @click="showingClientes = !showingClientes" class="w-full group relative flex items-center justify-between px-4 py-3 text-sm font-medium rounded-xl transition-all duration-200 cursor-pointer" :class="(route().current('clientes.*')) ? 'text-primary-600 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-3 transition-colors duration-200" :class="(route().current('clientes.*')) ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span v-if="open">Clientes</span>
                    </div>
                    <svg v-if="open" :class="{'rotate-180': showingClientes}" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>

                <div v-show="open && showingClientes" class="mt-1 space-y-1 overflow-hidden transition-all duration-300">
                    <Link :href="route('clientes.index')" :class="route().current('clientes.index') || route().current('clientes.show') || route().current('clientes.create') || route().current('clientes.edit') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Lista de Clientes</span>
                    </Link>
                    <Link :href="route('clientes.invoices.overdue')" :class="route().current('clientes.invoices.overdue') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Faturas em Atraso</span>
                    </Link>
                </div>
            </div>

            <NavLink :href="route('empresas.index')" :active="route().current('empresas.*')" class="group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-200" :class="route().current('empresas.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'">
                <svg class="w-5 h-5 mr-3 transition-colors duration-200" :class="route().current('empresas.*') ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                <span v-if="open">Empresas</span>
            </NavLink>

            <!-- WhatsApp Submenu -->
            <div>
                <button type="button" @click="showingWhatsapp = !showingWhatsapp" class="w-full group relative flex items-center justify-between px-4 py-3 text-sm font-medium rounded-xl transition-all duration-200 cursor-pointer" :class="(route().current('whatsapp.*')) ? 'text-primary-600 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-3 transition-colors duration-200" :class="(route().current('whatsapp.*')) ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                        <span v-if="open">WhatsApp</span>
                    </div>
                    <svg v-if="open" :class="{'rotate-180': showingWhatsapp}" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>

                <div v-show="open && showingWhatsapp" class="mt-1 space-y-1 overflow-hidden transition-all duration-300">
                    <Link :href="route('whatsapp.index')" :class="route().current('whatsapp.index') || route().current('whatsapp.show') || route().current('whatsapp.create') || route().current('whatsapp.edit') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Cadastro de Números</span>
                    </Link>
                    <Link :href="route('whatsapp.reports.index')" :class="route().current('whatsapp.reports.index') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Relatórios de Envio</span>
                    </Link>
                    <Link :href="route('whatsapp.future.index')" :class="route().current('whatsapp.future.index') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Envios Futuros</span>
                    </Link>
                </div>
            </div>

            <p v-if="open" class="px-4 mt-6 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Administração</p>

             <NavLink :href="route('users.index')" :active="route().current('users.*')" class="group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-200" :class="route().current('users.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'">
                <svg class="w-5 h-5 mr-3 transition-colors duration-200" :class="route().current('users.*') ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                <span v-if="open">Usuários</span>
            </NavLink>

            <!-- Settings Submenu -->
            <div>
                <button @click="showingSettings = !showingSettings" class="w-full group relative flex items-center justify-between px-4 py-3 text-sm font-medium rounded-xl transition-all duration-200 cursor-pointer" :class="(route().current('settings.*') || route().current('profile.*')) ? 'text-primary-600 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-3 transition-colors duration-200" :class="(route().current('settings.*') || route().current('profile.*')) ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <span v-if="open">Configurações</span>
                    </div>
                    <svg v-if="open" :class="{'rotate-180': showingSettings}" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>

                <div v-show="open && showingSettings" class="mt-1 space-y-1 overflow-hidden transition-all duration-300">
                    <Link :href="route('settings.templates.index')" :class="route().current('settings.templates.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Mensagens Padrão</span>
                    </Link>
                    <Link :href="route('settings.contaazul.index')" :class="route().current('settings.contaazul.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Conta Azul</span>
                    </Link>
                    <Link :href="route('settings.crons.index')" :class="route().current('settings.crons.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Crons (Autom.)</span>
                    </Link>
                    <Link :href="route('settings.restrictions.index')" :class="route().current('settings.restrictions.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Restrições e Envio</span>
                    </Link>
                    <Link :href="route('settings.roles.index')" :class="route().current('settings.roles.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Perfis</span>
                    </Link>
                    <Link :href="route('settings.permissions.index')" :class="route().current('settings.permissions.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Permissões</span>
                    </Link>
                    <Link :href="route('settings.orchestrator.index')" :class="route().current('settings.orchestrator.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Orquestrador</span>
                    </Link>
                    <Link :href="route('settings.system.index')" :class="route().current('settings.system.*') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Configurações do Sistema</span>
                    </Link>
                    <Link :href="route('profile.edit')" :class="route().current('profile.edit') ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-white'" class="group relative flex items-center pl-12 pr-4 py-2 text-sm font-medium rounded-xl transition-all duration-200">
                        <span>Meu Perfil</span>
                    </Link>
                </div>
            </div>
        </nav>
        
        <!-- User Profile Minimal -->
        <div class="border-t border-gray-100 dark:border-gray-700 p-4 shrink-0">
             <div class="flex items-center gap-3" :class="{'justify-center': !open}">
                <!-- Avatar -->
                <div class="h-10 w-10 shrink-0 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center text-primary-600 dark:text-primary-300 font-bold">
                    {{ $page.props.auth.user.name.charAt(0) }}
                </div>
                
                <!-- Info & Actions (Visible only when open) -->
                <div v-if="open" class="flex-1 min-w-0 flex items-center justify-between">
                    <div class="min-w-0 mr-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate" :title="$page.props.auth.user.name">
                            {{ $page.props.auth.user.name }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate" :title="$page.props.auth.user.email">
                            {{ $page.props.auth.user.email }}
                        </p>
                    </div>

                    <div class="flex items-center gap-1">
                        <!-- Dark Mode Toggle -->
                        <button @click="toggleDark" class="p-1.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" title="Alternar tema">
                            <!-- Sun icon -->
                            <svg v-if="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                            <!-- Moon icon -->
                            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                        </button>

                        <!-- Logout -->
                        <Link :href="route('logout')" method="post" as="button" class="p-1.5 text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors" title="Sair">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </aside>
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
