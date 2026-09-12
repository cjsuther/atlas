import http, { downloadBlob } from './http';

const BASE = '/contratos-ejecucion';

export const contratosEjecucionService = {
    list: (params) => http.get(BASE, { params }).then(r => r.data),
    get:  (id, params) => http.get(`${BASE}/${id}`, { params }).then(r => r.data),
    create: (data) => http.post(BASE, data).then(r => r.data),
    update: (id, data) => http.put(`${BASE}/${id}`, data).then(r => r.data),
    remove: (id) => http.delete(`${BASE}/${id}`).then(r => r.data),

    /** Transfiere el expediente completo a otra cuenta operativa (sólo admin_sistema). */
    transferir: (id, data) => http.post(`${BASE}/${id}/transferir`, data).then(r => r.data),

    /** Árbol de la estructura con las cuentas de cada nodo, para los selectores. */
    arbolEstructura: () => http.get('/estructura/arbol').then(r => r.data.data),
    exportExcel: (params) => downloadBlob(`${BASE}/export/excel`, params,
        `atlas-expedientes-${new Date().toISOString().slice(0,10)}.xlsx`),
};
