import http from './http';

export const authService = {
    login: (username, password) => http.post('/auth/login', { username, password }).then(r => r.data),
    logout: () => http.post('/auth/logout').then(r => r.data),
    me:     () => http.get('/auth/me').then(r => r.data),

    /** Preferencias de visualización (por ahora, la agrupación de saldos del panel). */
    savePreferencias: (data) => http.put('/auth/preferencias', data).then(r => r.data),
};
