import { createRouter, createWebHashHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
    { path: '/login', name: 'login', component: () => import('@/views/Login.vue'), meta: { public: true } },

    {
        path: '/',
        component: () => import('@/components/AppShell.vue'),
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: { name: 'panel' } },

            { path: 'panel', name: 'panel', component: () => import('@/views/Panel.vue'),
              meta: { title: 'Panel de Control' } },

            // Contratos Principal
            { path: 'contratos-principal', name: 'contratos-principal',
              component: () => import('@/views/contratos/PrincipalList.vue'),
              meta: { title: 'Contratos Principales' } },
            { path: 'contratos-principal/nuevo', name: 'contratos-principal-nuevo',
              component: () => import('@/views/contratos/PrincipalForm.vue'),
              meta: { title: 'Nuevo Contrato Principal', requiresEdit: true } },
            { path: 'contratos-principal/:id', name: 'contratos-principal-detalle',
              component: () => import('@/views/contratos/PrincipalDetail.vue'),
              meta: { title: 'Detalle de Contrato Principal' } },
            { path: 'contratos-principal/:id/editar', name: 'contratos-principal-editar',
              component: () => import('@/views/contratos/PrincipalForm.vue'),
              meta: { title: 'Editar Contrato Principal', requiresEdit: true } },

            // Contratos de Ejecución
            { path: 'contratos-ejecucion', name: 'contratos-ejecucion',
              component: () => import('@/views/contratos/EjecucionList.vue'),
              meta: { title: 'Contratos de Ejecución' } },
            { path: 'contratos-ejecucion/nuevo', name: 'contratos-ejecucion-nuevo',
              component: () => import('@/views/contratos/EjecucionForm.vue'),
              meta: { title: 'Nuevo Contrato de Ejecución', requiresEdit: true } },
            { path: 'contratos-ejecucion/:id', name: 'contratos-ejecucion-detalle',
              component: () => import('@/views/contratos/EjecucionDetail.vue'),
              meta: { title: 'Detalle de Contrato de Ejecución' } },
            { path: 'contratos-ejecucion/:id/editar', name: 'contratos-ejecucion-editar',
              component: () => import('@/views/contratos/EjecucionForm.vue'),
              meta: { title: 'Editar Contrato de Ejecución', requiresEdit: true } },

            // Catálogos
            { path: 'catalogos/:slug', name: 'catalogo',
              component: () => import('@/views/catalogos/Catalogo.vue') },

            // Usuarios (admin)
            { path: 'usuarios', name: 'usuarios',
              component: () => import('@/views/Usuarios.vue'),
              meta: { title: 'Usuarios y Roles', requiresRole: ['admin'] } },

            // Exportar / Importar base de datos (admin)
            { path: 'export-import', name: 'export-import',
              component: () => import('@/views/admin/ExportImport.vue'),
              meta: { title: 'Exportar / Importar', requiresRole: ['admin'] } },
        ],
    },

    { path: '/:pathMatch(.*)*', name: 'not-found',
      component: () => import('@/views/NotFound.vue') },
];

const router = createRouter({
    history: createWebHashHistory(),
    routes,
});

router.beforeEach((to) => {
    const auth = useAuthStore();
    if (!to.meta?.public && !auth.isAuthenticated) {
        return { name: 'login' };
    }
    if (to.name === 'login' && auth.isAuthenticated) {
        return { name: 'panel' };
    }
    if (to.meta?.requiresRole && !to.meta.requiresRole.includes(auth.role)) {
        return { name: 'panel' };
    }
    if (to.meta?.requiresEdit && !auth.canEdit) {
        return { name: 'panel' };
    }
    return true;
});

export default router;
