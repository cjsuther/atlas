import http from './http';

export const panelService = {
    indicadores:  (params) => http.get('/panel/indicadores',  { params }).then(r => r.data?.data ?? r.data),
    saldos:       (params) => http.get('/panel/saldos',       { params }).then(r => r.data?.data ?? r.data),
    porGerencia:  (params) => http.get('/panel/por-gerencia', { params }).then(r => r.data?.data ?? r.data),
    porAccion:    (params) => http.get('/panel/por-accion',   { params }).then(r => r.data?.data ?? r.data),
};
