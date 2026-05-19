import http from './http';

export const usuariosService = {
    list:   (params) => http.get('/usuarios', { params }).then(r => r.data),
    update: (username, data) => http.put(`/usuarios/${encodeURIComponent(username)}`, data).then(r => r.data),
};
