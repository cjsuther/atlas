import http from './http';

export const usuariosService = {
    list:          (params)     => http.get('/usuarios', { params }).then(r => r.data),
    get:           (username)   => http.get(`/usuarios/${encodeURIComponent(username)}`).then(r => r.data),
    create:        (data)       => http.post('/usuarios', data).then(r => r.data),
    update:        (username, data) => http.put(`/usuarios/${encodeURIComponent(username)}`, data).then(r => r.data),
    remove:        (username)   => http.delete(`/usuarios/${encodeURIComponent(username)}`).then(r => r.data),
    resetPassword: (username, data) => http.post(`/usuarios/${encodeURIComponent(username)}/password`, data).then(r => r.data),
};
