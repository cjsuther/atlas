import http from './http';

export const authService = {
    login: (username, password) => http.post('/auth/login', { username, password }).then(r => r.data),
    logout: () => http.post('/auth/logout').then(r => r.data),
    me:     () => http.get('/auth/me').then(r => r.data),
};
