import http from './http';

export const panelService = {
    indicadores:  (params) => http.get('/panel/indicadores',  { params }).then(r => r.data?.data ?? r.data),
    calculados:   (params) => http.get('/panel/calculados',   { params }).then(r => r.data?.data ?? r.data),
    saldos:       (params) => http.get('/panel/saldos',       { params }).then(r => r.data?.data ?? r.data),
    porUvt:       (params) => http.get('/panel/por-uvt',      { params }).then(r => r.data?.data ?? r.data),
    porGerencia:  (params) => http.get('/panel/por-gerencia', { params }).then(r => r.data?.data ?? r.data),
    porTipo:      (params) => http.get('/panel/por-tipo',     { params }).then(r => r.data?.data ?? r.data),
    porEstado:    (params) => http.get('/panel/por-estado',   { params }).then(r => r.data?.data ?? r.data),
    porMoneda:    (params) => http.get('/panel/por-moneda',   { params }).then(r => r.data?.data ?? r.data),
    porAccion:    (params) => http.get('/panel/por-accion',   { params }).then(r => r.data?.data ?? r.data),
    vencimientos: (params) => http.get('/panel/vencimientos', { params }).then(r => r.data?.data ?? r.data),
    rankings:     (params) => http.get('/panel/rankings',     { params }).then(r => r.data?.data ?? r.data),
};
