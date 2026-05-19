import http from './http';

export const historialService = {
    forRecord: (tabla, id, params) =>
        http.get(`/historial/${tabla}/${id}`, { params }).then(r => r.data),
};
