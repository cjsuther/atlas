import { defineStore } from 'pinia';

const TOKEN_KEY = 'atlas_token';
const USER_KEY  = 'atlas_user';

/**
 * Roles y alcance (ver backend/app/Models/UserRole.php):
 *   admin_sistema     : todas las gerencias de área, gerencias y contratos.
 *   admin_gerencia    : contratos y operadores de su Gerencia de Área.
 *   operador_gerencia : contratos de su Gerencia de Área.
 *   sin_acceso        : se autentica y nada más; es el rol con el que se dan de
 *                       alta los usuarios que llegan por LDAP.
 */
export const ROLES = {
    ADMIN_SISTEMA:     'admin_sistema',
    ADMIN_GERENCIA:    'admin_gerencia',
    OPERADOR_GERENCIA: 'operador_gerencia',
    SIN_ACCESO:        'sin_acceso',
};

export const ROL_LABELS = {
    [ROLES.ADMIN_SISTEMA]:     'Administrador de sistema',
    [ROLES.ADMIN_GERENCIA]:    'Administrador de gerencia',
    [ROLES.OPERADOR_GERENCIA]: 'Operador de gerencia',
    [ROLES.SIN_ACCESO]:        'Sin acceso',
};

export const AGRUPACIONES_SALDO = [
    { value: 'gerencia_area', label: 'Por Gerencia de Área' },
    { value: 'subsector',     label: 'Por Subsector' },
    { value: 'contrato',      label: 'Por Contrato' },
];

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY) || null,
        user:  JSON.parse(localStorage.getItem(USER_KEY) || 'null'),
    }),
    getters: {
        isAuthenticated: (s) => !!s.token,
        role: (s) => s.user?.rol ?? null,

        isAdminSistema:  (s) => s.user?.rol === ROLES.ADMIN_SISTEMA,
        isAdminGerencia: (s) => s.user?.rol === ROLES.ADMIN_GERENCIA,
        isOperador:      (s) => s.user?.rol === ROLES.OPERADOR_GERENCIA,

        /** Se autenticó pero todavía no tiene permisos asignados. */
        sinAcceso: (s) => s.user?.rol === ROLES.SIN_ACCESO,

        /** Los roles operativos administran contratos; la diferencia es la gerencia. */
        canEdit: (s) => !!s.user?.rol && s.user.rol !== ROLES.SIN_ACCESO,
        /** Sólo el administrador de sistema mantiene catálogos y estructura. */
        canAdminEstructura: (s) => s.user?.rol === ROLES.ADMIN_SISTEMA,
        /** Administración de usuarios: de todo el sistema o de la propia gerencia. */
        canAdminUsuarios: (s) => s.user?.rol === ROLES.ADMIN_SISTEMA
                              || s.user?.rol === ROLES.ADMIN_GERENCIA,

        sectorId:     (s) => s.user?.sector_id ?? null,
        gerenciaArea: (s) => s.user?.gerencia_area ?? null,
        rolLabel:     (s) => ROL_LABELS[s.user?.rol] ?? (s.user?.rol || ''),
        saldosAgrupacion: (s) => s.user?.saldos_agrupacion || 'gerencia_area',

        hasRole: (s) => (...roles) => roles.includes(s.user?.rol),
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
