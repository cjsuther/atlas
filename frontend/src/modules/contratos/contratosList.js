import { api, auth, downloadFile } from '../../services/api.js';
import { renderShell, setPageTitle } from '../../components/layout.js';
import { renderPager } from '../../components/pager.js';
import { confirmDialog } from '../../components/modal.js';
import { fmtDate, fmtMoney, badgeForEstado, escapeHtml, debounce } from '../../services/format.js';
import { toast } from '../../services/toast.js';
import { router } from '../../services/router.js';

const STORAGE_FILTERS = 'atlas_contratos_filters';

export async function renderContratosList() {
    const main = renderShell('contratos', 'Contratos');
    setPageTitle('Gestión de Contratos');

    const canEdit  = auth.hasRole('admin', 'operador');
    const canDelete = auth.hasRole('admin');

    // Cargar filtros guardados
    const saved = JSON.parse(sessionStorage.getItem(STORAGE_FILTERS) || '{}');
    const state = {
        page: saved.page || 1,
        per_page: 20,
        order_by: saved.order_by || 'id_cto',
        order_dir: saved.order_dir || 'desc',
        search: saved.search || '',
        estado_id: saved.estado_id || '',
        tipo_de_contrato_id: saved.tipo_de_contrato_id || '',
        sector_id: saved.sector_id || '',
        solicitante_id: saved.solicitante_id || '',
        uvt_id: saved.uvt_id || '',
        fecha_inicio_desde: saved.fecha_inicio_desde || '',
        fecha_inicio_hasta: saved.fecha_inicio_hasta || '',
        fecha_vencimiento_desde: saved.fecha_vencimiento_desde || '',
        fecha_vencimiento_hasta: saved.fecha_vencimiento_hasta || '',
    };

    main.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Contratos</h1>
                <p class="page-subtitle">Listado completo con filtros y exportación</p>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary" id="btn-export">⬇ Exportar Excel</button>
                ${canEdit ? `<button class="btn btn-primary" id="btn-new">+ Nuevo contrato</button>` : ''}
            </div>
        </div>

        <div class="filters-panel" id="filters">
            <div class="filters-grid">
                <div class="field" style="margin:0;">
                    <label>Búsqueda</label>
                    <input type="text" class="input" id="f-search" placeholder="Proyecto, expediente, descripción..." value="${escapeHtml(state.search)}">
                </div>
                <div class="field" style="margin:0;">
                    <label>Estado</label>
                    <select class="select" id="f-estado"><option value="">Todos</option></select>
                </div>
                <div class="field" style="margin:0;">
                    <label>Tipo de contrato</label>
                    <select class="select" id="f-tipo"><option value="">Todos</option></select>
                </div>
                <div class="field" style="margin:0;">
                    <label>Sector</label>
                    <select class="select" id="f-sector"><option value="">Todos</option></select>
                </div>
                <div class="field" style="margin:0;">
                    <label>Solicitante</label>
                    <select class="select" id="f-solic"><option value="">Todos</option></select>
                </div>
                <div class="field" style="margin:0;">
                    <label>UVT</label>
                    <select class="select" id="f-uvt"><option value="">Todas</option></select>
                </div>
                <div class="field" style="margin:0;">
                    <label>Inicio desde</label>
                    <input type="date" class="input" id="f-inicio-desde" value="${state.fecha_inicio_desde || ''}">
                </div>
                <div class="field" style="margin:0;">
                    <label>Inicio hasta</label>
                    <input type="date" class="input" id="f-inicio-hasta" value="${state.fecha_inicio_hasta || ''}">
                </div>
                <div class="field" style="margin:0;">
                    <label>Vencimiento desde</label>
                    <input type="date" class="input" id="f-venc-desde" value="${state.fecha_vencimiento_desde || ''}">
                </div>
                <div class="field" style="margin:0;">
                    <label>Vencimiento hasta</label>
                    <input type="date" class="input" id="f-venc-hasta" value="${state.fecha_vencimiento_hasta || ''}">
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-ghost" id="btn-clear">Limpiar</button>
            </div>
        </div>

        <div id="table-area">
            <div class="empty-state"><span class="loader dark"></span> Cargando...</div>
        </div>
        <div id="pager"></div>
    `;

    // Cargar selects de filtros
    await Promise.all([
        loadSelect('estados', 'estado_id', 'estado_nombre', 'f-estado', state.estado_id),
        loadSelect('tipos-contrato', 'id_tipo', t => `${t.tipo} — ${t.nombre}`, 'f-tipo', state.tipo_de_contrato_id),
        loadSelect('sectores', 'sector_id', 'nombre', 'f-sector', state.sector_id),
        loadSelect('solicitantes', 'solicitante_id', 'razon_social', 'f-solic', state.solicitante_id),
        loadSelect('uvt', 'uvt_id', t => `${t.siglas} — ${t.nombre}`, 'f-uvt', state.uvt_id),
    ]);

    // Listeners
    const onChange = () => {
        state.page = 1;
        state.search = document.getElementById('f-search').value;
        state.estado_id = document.getElementById('f-estado').value;
        state.tipo_de_contrato_id = document.getElementById('f-tipo').value;
        state.sector_id = document.getElementById('f-sector').value;
        state.solicitante_id = document.getElementById('f-solic').value;
        state.uvt_id = document.getElementById('f-uvt').value;
        state.fecha_inicio_desde = document.getElementById('f-inicio-desde').value;
        state.fecha_inicio_hasta = document.getElementById('f-inicio-hasta').value;
        state.fecha_vencimiento_desde = document.getElementById('f-venc-desde').value;
        state.fecha_vencimiento_hasta = document.getElementById('f-venc-hasta').value;
        sessionStorage.setItem(STORAGE_FILTERS, JSON.stringify(state));
        load();
    };

    document.getElementById('f-search').addEventListener('input', debounce(onChange, 350));
    ['f-estado','f-tipo','f-sector','f-solic','f-uvt','f-inicio-desde','f-inicio-hasta','f-venc-desde','f-venc-hasta']
        .forEach(id => document.getElementById(id).addEventListener('change', onChange));

    document.getElementById('btn-clear').addEventListener('click', () => {
        sessionStorage.removeItem(STORAGE_FILTERS);
        renderContratosList();
    });

    if (canEdit) document.getElementById('btn-new').addEventListener('click', () => router.navigate('/contratos/nuevo'));
    document.getElementById('btn-export').addEventListener('click', async () => {
        try {
            await downloadFile('/contratos/export/excel', filtersToParams(state), 'atlas-contratos.xlsx');
            toast.success('Exportación generada.');
        } catch (e) {
            toast.error('No se pudo exportar.');
        }
    });

    async function load() {
        const tableArea = document.getElementById('table-area');
        tableArea.innerHTML = '<div class="empty-state"><span class="loader dark"></span> Cargando...</div>';
        try {
            const data = await api.get('/contratos', filtersToParams(state));
            renderTable(tableArea, data, state, load, canEdit, canDelete);
            renderPager(document.getElementById('pager'), data, p => { state.page = p; sessionStorage.setItem(STORAGE_FILTERS, JSON.stringify(state)); load(); });
        } catch (e) {
            tableArea.innerHTML = `<div class="empty-state">Error: ${escapeHtml(e.message)}</div>`;
        }
    }

    load();
}

function filtersToParams(state) {
    return {
        page: state.page,
        per_page: state.per_page,
        order_by: state.order_by,
        order_dir: state.order_dir,
        search: state.search,
        estado_id: state.estado_id,
        tipo_de_contrato_id: state.tipo_de_contrato_id,
        sector_id: state.sector_id,
        solicitante_id: state.solicitante_id,
        uvt_id: state.uvt_id,
        fecha_inicio_desde: state.fecha_inicio_desde,
        fecha_inicio_hasta: state.fecha_inicio_hasta,
        fecha_vencimiento_desde: state.fecha_vencimiento_desde,
        fecha_vencimiento_hasta: state.fecha_vencimiento_hasta,
    };
}

async function loadSelect(endpoint, valueKey, labelKey, elId, currentValue) {
    try {
        const res = await api.get(`/${endpoint}`, { per_page: 200 });
        const items = res.data || [];
        const sel = document.getElementById(elId);
        items.forEach(it => {
            const opt = document.createElement('option');
            opt.value = it[valueKey];
            opt.textContent = typeof labelKey === 'function' ? labelKey(it) : it[labelKey];
            if (String(currentValue) === String(opt.value)) opt.selected = true;
            sel.appendChild(opt);
        });
    } catch (e) {
        // Silencioso
    }
}

function renderTable(container, paginator, state, reload, canEdit, canDelete) {
    const items = paginator.data || [];
    if (items.length === 0) {
        container.innerHTML = '<div class="empty-state">No se encontraron contratos con los filtros aplicados.</div>';
        return;
    }

    const sortIndicator = (col) => {
        if (state.order_by !== col) return '';
        return state.order_dir === 'asc' ? ' ▲' : ' ▼';
    };

    container.innerHTML = `
        <div class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th class="sortable" data-col="id_cto">ID${sortIndicator('id_cto')}</th>
                        <th class="sortable" data-col="nombre_proy">Proyecto${sortIndicator('nombre_proy')}</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Solicitante</th>
                        <th>Sector</th>
                        <th class="sortable" data-col="fecha_inicio">Inicio${sortIndicator('fecha_inicio')}</th>
                        <th class="sortable" data-col="fecha_vencimiento">Vencimiento${sortIndicator('fecha_vencimiento')}</th>
                        <th>Monto AR$</th>
                        <th>Monto USD</th>
                        <th style="text-align:right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(c => {
                        const b = badgeForEstado(c.estado);
                        return `
                        <tr>
                            <td>${c.id_cto}</td>
                            <td>${escapeHtml(c.nombre_proy)}</td>
                            <td>${escapeHtml(c.tipo_contrato?.tipo || '')}</td>
                            <td><span class="badge ${b.cls}">${b.label}</span></td>
                            <td>${escapeHtml(c.solicitante?.razon_social || '')}</td>
                            <td>${escapeHtml(c.sector?.nombre || '')}</td>
                            <td>${fmtDate(c.fecha_inicio)}</td>
                            <td>${fmtDate(c.fecha_vencimiento)}</td>
                            <td>${fmtMoney(c.monto_pesos)}</td>
                            <td>${fmtMoney(c.monto_usd)}</td>
                            <td>
                                <div class="actions">
                                    <button data-act="view" data-id="${c.id_cto}" title="Ver detalle">Ver</button>
                                    ${canEdit ? `<button data-act="edit" data-id="${c.id_cto}" title="Editar">Editar</button>` : ''}
                                    ${canDelete ? `<button class="danger" data-act="del" data-id="${c.id_cto}" title="Baja lógica">Baja</button>` : ''}
                                </div>
                            </td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;

    container.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.col;
            if (state.order_by === col) state.order_dir = state.order_dir === 'asc' ? 'desc' : 'asc';
            else { state.order_by = col; state.order_dir = 'asc'; }
            sessionStorage.setItem(STORAGE_FILTERS, JSON.stringify(state));
            reload();
        });
    });

    container.querySelectorAll('button[data-act]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            if (btn.dataset.act === 'view')  router.navigate(`/contratos/${id}`);
            if (btn.dataset.act === 'edit')  router.navigate(`/contratos/${id}/editar`);
            if (btn.dataset.act === 'del') {
                const ok = await confirmDialog({
                    title: 'Dar de baja contrato',
                    message: `¿Marcar el contrato #${id} como "Sin efecto"? Esta acción se registra como baja lógica.`,
                    confirmText: 'Dar de baja',
                    danger: true,
                });
                if (ok) {
                    try {
                        await api.del(`/contratos/${id}`);
                        toast.success('Contrato dado de baja.');
                        reload();
                    } catch (e) {
                        toast.error(e.message || 'Error al dar de baja.');
                    }
                }
            }
        });
    });
}
