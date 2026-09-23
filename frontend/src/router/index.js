import { createRouter, createWebHashHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
    { path: '/login', name: 'login', component: () => import('@/views/Login.vue'), meta: { public: true } },

    // Pantalla para quien se autenticó pero todavía no tiene permisos asignados.
    // Va fuera del layout: no hay menú que mostrarle.
    { path: '/sin-acceso', name: 'sin-acceso', component: () => import('@/views/SinAcceso.vue') },

    {
        path: '/',
        component: () => import('@/components/AppShell.vue'),
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: { name: 'panel' } },

            { path: 'panel', name: 'panel', component: () => import('@/views/Panel.vue'),
              meta: { title: 'Panel de Control' } },

            // Expedientes: el registro que se imputa a una cuenta. «Contrato» es
            // el tercer nivel de la estructura, no esto.
            { path: 'expedientes', name: 'expedientes',
              component: () => import('@/views/expedientes/ExpedientesList.vue'),
              meta: { title: 'Expedientes' } },
            { path: 'expedientes/nuevo', name: 'expedientes-nuevo',
              component: () => import('@/views/expedientes/ExpedienteFormView.vue'),
              meta: { title: 'Nuevo Expediente', requiresEdit: true } },
            { path: 'expedientes/:id', name: 'expedientes-detalle',
              component: () => import('@/views/expedientes/ExpedienteDetail.vue'),
              meta: { title: 'Detalle de Expediente' } },
            { path: 'expedientes/:id/movimientos', name: 'expedientes-movimientos',
              component: () => import('@/views/expedientes/ExpedienteMovimientos.vue'),
              meta: { title: 'Movimientos del Expediente' } },
            { path: 'expedientes/:id/editar', name: 'expedientes-editar',
              component: () => import('@/views/expedientes/ExpedienteFormView.vue'),
              meta: { title: 'Editar Expediente', requiresEdit: true } },

            // Cuenta operativa: su saldo y el historial de lo que se registró.
            { path: 'cuentas/:id', name: 'cuenta-detalle',
              component: () => import('@/views/cuentas/CuentaDetail.vue'),
              meta: { title: 'Cuenta' } },

            // Estructura organizativa y catálogos
            { path: 'catalogos/:slug', name: 'catalogo',
              component: () => import('@/views/catalogos/Catalogo.vue') },

            // Usuarios y sus permisos sobre el árbol
            { path: 'usuarios', name: 'usuarios',
              component: () => import('@/views/Usuarios.vue'),
              meta: { title: 'Usuarios y Permisos', requiresAdmin: true } },

            // Exportar / Importar base de datos
            { path: 'export-import', name: 'export-import',
              component: () => import('@/views/admin/ExportImport.vue'),
              meta: { title: 'Exportar / Importar', requiresAdmin: true } },
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
        return auth.sinAcceso ? { name: 'sin-acceso' } : { name: 'panel' };
    }
    // Sin permisos asignados no hay nada que ver: cualquier ruta cae en el aviso.
    if (auth.isAuthenticated && auth.sinAcceso && to.name !== 'sin-acceso') {
        return { name: 'sin-acceso' };
    }
    if (to.name === 'sin-acceso' && auth.isAuthenticated && !auth.sinAcceso) {
        return { name: 'panel' };
    }
    if (to.meta?.requiresAdmin && !auth.isAdmin) {
        return { name: 'panel' };
    }
    if (to.meta?.requiresEdit && !auth.canEdit) {
        return { name: 'panel' };
    }
    return true;
});

export default router;
