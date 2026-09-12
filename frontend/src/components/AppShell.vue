<template>
    <div :class="['app-shell', { collapsed: ui.sidebarCollapsed, 'mobile-open': ui.mobileOpen }]">
        <div class="sidebar-backdrop" @click="ui.closeMobile()" />

        <aside class="app-sidebar">
            <div class="brand">
                <AtlasLogo :size="28" />
                <span class="name">ATLAS</span>
            </div>
            <nav>
                <template v-for="(section, sIdx) in visibleNav" :key="sIdx">
                    <div class="nav-section-title">{{ section.title }}</div>
                    <router-link v-for="item in section.items" :key="item.to.name + (item.to.params?.slug || '')"
                                 :to="item.to" class="nav-item"
                                 @click="onNavClick">
                        <IconLib :name="item.icon" :size="18" />
                        <span class="label">{{ item.label }}</span>
                    </router-link>
                </template>
            </nav>
        </aside>

        <header class="app-header">
            <div style="display:flex;align-items:center;">
                <button class="toggle-sidebar" @click="ui.toggleSidebar()" title="Menú">
                    <IconLib name="menu" :size="20" />
                </button>
                <span class="title">{{ pageTitle }}</span>
            </div>
            <div class="user-area">
                <div class="user-info">
                    <div class="name">{{ auth.user?.display_name || auth.user?.username || '—' }}</div>
                    <div class="role">{{ auth.alcanceLabel }}</div>
                </div>
                <button class="btn btn-ghost" @click="logout" title="Cerrar sesión">
                    <IconLib name="logout" :size="18" />
                </button>
            </div>
        </header>

        <main class="app-main">
            <router-view />
        </main>
    </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { useToast } from '@/composables/useToast';
import { authService } from '@/services/auth';
import AtlasLogo from './AtlasLogo.vue';
import IconLib from './IconLib.vue';

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const pageTitle = computed(() => route.meta?.title || '');

/** Visible para cualquiera con permisos; `soloAdmin` reserva la opción. */

const NAV_SECTIONS = [
    {
        title: 'Principal',
        items: [
            { label: 'Panel de Control', icon: 'dashboard', to: { name: 'panel' } },
            { label: 'Expedientes',      icon: 'contratos', to: { name: 'contratos-ejecucion' } },
        ],
    },
    {
        title: 'Estructura',
        items: [
            { label: 'Estructura',           icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'sectores' } } },
            { label: 'Cuentas Operativas',   icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'cuentas-operativas' } } },
        ],
    },
    {
        title: 'Catálogos',
        items: [
            { label: 'Tipos de expediente', icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'tipos-contrato-ejecucion' } } },
            { label: 'Estados',           icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'estados-ejecucion' } } },
            { label: 'Solicitantes',      icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'solicitantes' } } },
            { label: 'UVTs',              icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'uvt' } } },
            { label: 'Personal',          icon: 'catalogos', to: { name: 'catalogo', params: { slug: 'personal' } } },
        ],
    },
    {
        title: 'Administración',
        items: [
            { label: 'Usuarios y Permisos', icon: 'users',    to: { name: 'usuarios' },      soloAdmin: true },
            { label: 'Exportar / Importar', icon: 'database', to: { name: 'export-import' }, soloAdmin: true },
        ],
    },
];

const visibleNav = computed(() => NAV_SECTIONS
    .map(s => ({ ...s, items: s.items.filter(i => !i.soloAdmin || auth.isAdmin) }))
    .filter(s => s.items.length > 0));

function onNavClick() {
    if (window.matchMedia('(max-width: 768px)').matches) ui.closeMobile();
}

async function logout() {
    try { await authService.logout(); } catch { /* ignore */ }
    auth.clear();
    toast.info('Sesión cerrada.');
    router.replace({ name: 'login' });
}

// Refresca el usuario al montar (revalida token con backend).
onMounted(async () => {
    if (!auth.isAuthenticated) return;
    try {
        const r = await authService.me();
        if (r?.user) auth.setUser(r.user);
    } catch { /* 401 handler en interceptor */ }
});
</script>
