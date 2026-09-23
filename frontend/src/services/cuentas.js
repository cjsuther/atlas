import http from './http';

/**
 * Cuentas operativas: es donde vive el saldo y donde se registra la operatoria.
 * El ABM de cuentas va por el catálogo genérico; esto es lo propio del detalle.
 */
export const cuentasService = {
    get: (id) => http.get(`/cuentas-operativas/${id}`).then(r => r.data),

    /** GET /api/cuentas-operativas/{id}/movimientos */
    listMovimientos: (id, params) =>
        http.get(`/cuentas-operativas/${id}/movimientos`, { params }).then(r => r.data),
};
