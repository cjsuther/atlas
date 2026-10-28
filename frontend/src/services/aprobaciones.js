import http from './http';

export const aprobacionesService = {
    /** GET /api/aprobaciones — lo que espera aprobación en el alcance del usuario */
    bandeja: () => http.get('/aprobaciones').then(r => r.data),

    /** GET /api/aprobaciones/cantidad — para el contador del menú */
    cantidad: () => http.get('/aprobaciones/cantidad').then(r => r.data),

    aprobar: (id, observacion) =>
        http.post(`/aprobaciones/${id}/aprobar`, { observacion }).then(r => r.data),

    rechazar: (id, observacion) =>
        http.post(`/aprobaciones/${id}/rechazar`, { observacion }).then(r => r.data),
};

export const actividadesService = {
    /** GET /api/actividades — log de actividades, recortado al alcance */
    list: (params) => http.get('/actividades', { params }).then(r => r.data),
};
