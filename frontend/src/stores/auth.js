import { defineStore } from 'pinia';

const TOKEN_KEY = 'atlas_token';
const USER_KEY  = 'atlas_user';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem(TOKEN_KEY) || null,
        user:  JSON.parse(localStorage.getItem(USER_KEY) || 'null'),
    }),
    getters: {
        isAuthenticated: (s) => !!s.token,
        role:    (s) => s.user?.rol ?? null,
        isAdmin: (s) => s.user?.rol === 'admin',
        isOperador: (s) => s.user?.rol === 'operador',
        canEdit: (s) => s.user?.rol === 'admin' || s.user?.rol === 'operador',
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
