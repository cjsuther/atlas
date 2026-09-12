import { defineStore } from 'pinia';

const TOKEN_KEY = 'atlas_token';
const USER_KEY  = 'atlas_user';

/**
 * El alcance no es un rol: son permisos sobre el árbol de la estructura
 * (ver backend/app/Models/UserRole.php). A cada usuario se le asignan nodos
 * —o la raíz, que es toda la organización— con nivel de lectura o escritura,
 * y el permiso se hereda hacia abajo.
 *
 * Aparte del árbol está `es_admin`, que habilita la configuración del sistema.
 *
 * El servidor ya resuelve los dos atajos que la interfaz necesita para
 * habilitar o esconder acciones: `ve_todo` y `puede_editar`.
 */
export const NIVELES = {
    LECTURA:   'lectura',
    ESCRITURA: 'escritura',
};

export const NIVEL_LABELS = {
    [NIVELES.LECTURA]:   'Sólo ver',
    [NIVELES.ESCRITURA]: 'Ejecutar',
};

export const AGRUPACIONES_SALDO = [
    { value: 'gerencia_area', label: 'Por Gerencia de Área' },
    { value: 'subsector',     label: 'Por Gerencia' },
    { value: 'contrato',      label: 'Por Expediente' },
];

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY) || null,
        user:  JSON.parse(localStorage.getItem(USER_KEY) || 'null'),
    }),
    getters: {
        isAuthenticated: (s) => !!s.token,

        /** Administra la configuración: estructura, cuentas, catálogos y usuarios. */
        isAdmin: (s) => !!s.user?.es_admin,

        /** Se autenticó pero todavía no tiene ningún permiso asignado. */
        sinAcceso: (s) => !s.user?.es_admin && !(s.user?.permisos?.length),

        /** Tiene escritura en alguna rama, así que puede cargar y modificar. */
        canEdit: (s) => !!s.user?.puede_editar,

        /** Ve toda la organización: es administrador o tiene permiso sobre la raíz. */
        veTodo: (s) => !!s.user?.ve_todo,

        /** La configuración del sistema y la administración de usuarios. */
        canAdminEstructura: (s) => !!s.user?.es_admin,
        canAdminUsuarios:   (s) => !!s.user?.es_admin,

        permisos: (s) => s.user?.permisos ?? [],

        /** Resumen del alcance para mostrar en la barra superior. */
        alcanceLabel: (s) => {
            if (s.user?.es_admin) return 'Administrador del sistema';
            const p = s.user?.permisos ?? [];
            if (!p.length) return 'Sin permisos asignados';
            if (p.length === 1) {
                const nivel = p[0].nivel === 'escritura' ? 'Ejecuta' : 'Sólo ve';
                return `${nivel} · ${p[0].ruta}`;
            }
            return `${p.length} ramas asignadas`;
        },

        saldosAgrupacion: (s) => s.user?.saldos_agrupacion || 'gerencia_area',
    },
    actions: {
        setSession(token, user) {
            this.token = token;
            this.user = user;
            localStorage.setItem(TOKEN_KEY, token);
            localStorage.setItem(USER_KEY, JSON.stringify(user));
        },
        setUser(user) {
            this.user = user;
            localStorage.setItem(USER_KEY, JSON.stringify(user));
        },
        clear() {
            this.token = null;
            this.user = null;
            localStorage.removeItem(TOKEN_KEY);
            localStorage.removeItem(USER_KEY);
        },
    },
});
