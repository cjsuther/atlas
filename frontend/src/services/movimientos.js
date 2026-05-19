import http, { downloadBlob } from './http';

export const movimientosService = {
    /** GET /api/contratos-ejecucion/{id}/movimientos */
    listForContrato: (contratoId, params) =>
        http.get(`/contratos-ejecucion/${contratoId}/movimientos`, { params }).then(r => r.data),

    get: (id) => http.get(`/movimientos/${id}`).then(r => r.data),

    /**
     * Crea un movimiento. Si `data.factura` es File, envía multipart;
     * si no, envía JSON.
     */
    create: (contratoId, data) => {
        const fd = toFormData(data);
        return http.post(`/contratos-ejecucion/${contratoId}/movimientos`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }).then(r => r.data);
    },

    /**
     * Actualiza un movimiento. Para soportar multipart con PUT, usamos
     * POST + _method=PUT (clásico method spoofing de Laravel).
     */
    update: (id, data) => {
        const fd = toFormData(data);
        fd.append('_method', 'PUT');
        return http.post(`/movimientos/${id}`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }).then(r => r.data);
    },

    remove: (id) => http.delete(`/movimientos/${id}`).then(r => r.data),

    downloadFactura: (id, filename) =>
        downloadBlob(`/movimientos/${id}/factura`, {}, filename || `factura-${id}.pdf`),
};

function toFormData(obj) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(obj)) {
        if (v === null || v === undefined) continue;
        if (v instanceof File) {
            fd.append(k, v);
        } else if (typeof v === 'boolean') {
            fd.append(k, v ? '1' : '0');
        } else {
            fd.append(k, String(v));
        }
    }
    return fd;
}
