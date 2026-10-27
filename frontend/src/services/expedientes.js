import http, { downloadBlob } from './http';

const BASE = '/expedientes';

export const expedientesService = {
    list: (params) => http.get(BASE, { params }).then(r => r.data),
    get:  (id, params) => http.get(`${BASE}/${id}`, { params }).then(r => r.data),
    create: (data) => http.post(BASE, data).then(r => r.data),
    update: (id, data) => http.put(`${BASE}/${id}`, data).then(r => r.data),
    remove: (id) => http.delete(`${BASE}/${id}`).then(r => r.data),

    /** Quién hizo qué y cuándo sobre el expediente y sus movimientos. */
    historial: (id) => http.get(`${BASE}/${id}/historial`).then(r => r.data.data ?? []),

    /** Todos los expedientes vigentes (id y número), para elegir el de un movimiento. */
    opciones: () => http.get(`${BASE}/opciones`).then(r => r.data.data ?? []),

    /** Árbol de la estructura con las cuentas de cada nodo, para los selectores. */
    arbolEstructura: () => http.get('/estructura/arbol').then(r => r.data.data),
    exportExcel: (params) => downloadBlob(`${BASE}/export/excel`, params,
        `atlas-expedientes-${new Date().toISOString().slice(0,10)}.xlsx`),
};
