import { createRouter, createWebHashHistory } from 'vue-router';
import { ROLES, useAuthStore } from '@/stores/auth';

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

            // Expedientes (antes "contratos de ejecución"). Las rutas y el endpoint
            // conservan el nombre histórico; en la interfaz son los expedientes.
            // "Contrato" pasó a ser un nivel de la estructura, no este registro.
            { path: 'contratos', name: 'contratos-ejecucion',
              component: () => import('@/views/contratos/EjecucionList.vue'),
              meta: { title: 'Expedientes' } },
            { path: 'contratos/nuevo', name: 'contratos-ejecucion-nuevo',
              component: () => import('@/views/contratos/EjecucionForm.vue'),
              meta: { title: 'Nuevo Expediente', requiresEdit: true } },
            { path: 'contratos/:id', name: 'contratos-ejecucion-detalle',
              component: () => import('@/views/contratos/EjecucionDetail.vue'),
              meta: { title: 'Detalle de Expediente' } },
            { path: 'contratos/:id/ejecucion', name: 'contratos-ejecucion-movimientos',
              component: () => import('@/views/contratos/EjecucionMovimientos.vue'),
              meta: { title: 'Ejecución del Expediente' } },
            { path: 'contratos/:id/editar', name: 'contratos-ejecucion-editar',
              component: () => import('@/views/contratos/EjecucionForm.vue'),
              meta: { title: 'Editar Expediente', requiresEdit: true } },

            // Estructura organizativa y catálogos
            { path: 'catalogos/:slug', name: 'catalogo',
              component: () => import('@/views/catalogos/Catalogo.vue') },

            // Usuarios (administradores de sistema y de gerencia)
            { path: 'usuarios', name: 'usuarios',
              component: () => import('@/views/Usuarios.vue'),
              meta: { title: 'Usuarios y Roles',
                      requiresRole: [ROLES.ADMIN_SISTEMA, ROLES.ADMIN_GERENCIA] } },

            // Exportar / Importar base de datos
            { path: 'export-import', name: 'export-import',
              component: () => import('@/views/admin/ExportImport.vue'),
              meta: { title: 'Exportar / Importar', requiresRole: [ROLES.ADMIN_SISTEMA] } },
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
    if (to.meta?.requiresRole && !to.meta.requiresRole.includes(auth.role)) {
        return { name: 'panel' };
    }
    if (to.meta?.requiresEdit && !auth.canEdit) {
        return { name: 'panel' };
    }
    return true;
});

export default router;
