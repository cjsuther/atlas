import http, { downloadBlob } from './http';

const BASE = '/contratos-ejecucion';

export const contratosEjecucionService = {
    list: (params) => http.get(BASE, { params }).then(r => r.data),
    get:  (id, params) => http.get(`${BASE}/${id}`, { params }).then(r => r.data),
    create: (data) => http.post(BASE, data).then(r => r.data),
    update: (id, data) => http.put(`${BASE}/${id}`, data).then(r => r.data),
    remove: (id) => http.delete(`${BASE}/${id}`).then(r => r.data),
    exportExcel: (params) => downloadBlob(`${BASE}/export/excel`, params,
        `atlas-contratos-ejecucion-${new Date().toISOString().slice(0,10)}.xlsx`),
};
