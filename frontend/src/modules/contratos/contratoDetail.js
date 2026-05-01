import { api, auth } from '../../services/api.js';
import { renderShell, setPageTitle } from '../../components/layout.js';
import { fmtDate, fmtMoney, badgeForEstado, escapeHtml } from '../../services/format.js';
import { toast } from '../../services/toast.js';
import { router } from '../../services/router.js';
import { confirmDialog } from '../../components/modal.js';

export async function renderContratoDetail({ params }) {
    const id = Number(params.id);
    const main = renderShell('contratos', `Contrato #${id}`);
    setPageTitle(`Detalle de contrato #${id}`);

    main.innerHTML = '<div class="empty-state"><span class="loader dark"></span> Cargando...</div>';

    let c;
    try {
        const res = await api.get(`/contratos/${id}`);
        c = res.data;
    } catch (e) {
        main.innerHTML = `<div class="empty-state">Contrato no encontrado.</div>`;
        return;
    }
    if (!c) {
        main.innerHTML = `<div class="empty-state">Contrato no encontrado.</div>`;
        return;
    }

    const canEdit  = auth.hasRole('admin', 'operador');
    const canDelete = auth.hasRole('admin');
    const b = badgeForEstado(c.estado);

    const item = (label, value) => `
        <div class="item">
            <div class="label">${label}</div>
            <div class="value">${value === null || value === undefined || value === '' ? '—' : value}</div>
        </div>
    `;

    main.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h1 class="page-title">${escapeHtml(c.nombre_proy)}</h1>
                <p class="page-subtitle">
                    Contrato #${c.id_cto} · <span class="badge ${b.cls}">${b.label}</span>
                </p>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary" id="btn-back">← Volver</button>
                ${canEdit ? `<button class="btn btn-primary" id="btn-edit">Editar</button>` : ''}
                ${canDelete && c.estado_id !== 4 ? `<button class="btn btn-danger" id="btn-del">Dar de baja</button>` : ''}
            </div>
        </div>

        <div class="card">
            <div class="detail-section">
                <h4>Datos generales</h4>
                <div class="detail-grid">
                    ${item('ID',                  c.id_cto)}
                    ${item('Estado',              `<span class="badge ${b.cls}">${b.label}</span>`)}
                    ${item('Tipo',                c.tipo_contrato ? `${escapeHtml(c.tipo_contrato.tipo)} — ${escapeHtml(c.tipo_contrato.nombre)}` : '')}
                    ${item('Expediente',          escapeHtml(c.expediente || ''))}
                    ${item('Fecha de expediente', fmtDate(c.fecha_expediente))}
                    ${item('Solicitud / sector GDE', escapeHtml(c.solicitud_sector_gde || ''))}
                    ${item('Operatoria',          c.operatoria_id ?? '')}
                    ${item('Contrato padre',      c.dependencia_contractual ? `#${c.dependencia_contractual.id_cto} — ${escapeHtml(c.dependencia_contractual.nombre_proy)}` : '')}
                </div>
            </div>

            <div class="detail-section">
                <h4>Descripción</h4>
                <div>${escapeHtml(c.descripcion_objeto || '—').replace(/\n/g, '<br>')}</div>
            </div>

            <div class="detail-section">
                <h4>Partes y ubicación</h4>
                <div class="detail-grid">
                    ${item('Solicitante',  c.solicitante ? escapeHtml(c.solicitante.razon_social) : '')}
                    ${item('UVT',          c.uvt ? `${escapeHtml(c.uvt.siglas)} — ${escapeHtml(c.uvt.nombre)}` : '')}
                    ${item('Sector',       c.sector ? escapeHtml(c.sector.nombre) : '')}
                    ${item('Gerencia',     escapeHtml(c.gerencia || ''))}
                    ${item('Gerencia/área', escapeHtml(c.gerencia_area || ''))}
                    ${item('Responsable 1', c.resp1 ? `${c.resp1.legajo} — ${escapeHtml(c.resp1.apellido)}, ${escapeHtml(c.resp1.nombre)}` : '')}
                    ${item('Responsable 2', c.resp2 ? `${c.resp2.legajo} — ${escapeHtml(c.resp2.apellido)}, ${escapeHtml(c.resp2.nombre)}` : '')}
                </div>
            </div>

            <div class="detail-section">
                <h4>Fechas y plazos</h4>
                <div class="detail-grid">
                    ${item('Firma',         fmtDate(c.fecha_firma))}
                    ${item('Inicio',        fmtDate(c.fecha_inicio))}
                    ${item('Vencimiento',   fmtDate(c.fecha_vencimiento))}
                    ${item('Finalizado',    fmtDate(c.fecha_finalizado))}
                    ${item('Duración (meses)', c.duracion_meses ?? '')}
                    ${item('Atraso (meses)',   c.atraso_meses ?? '')}
                    ${item('Prórroga',         c.prorroga ? 'Sí' : 'No')}
                    ${item('Renovación automática', c.renovacion_automatica ? 'Sí' : 'No')}
                    ${item('Acta de finalización', escapeHtml(c.acta_finalizacion || ''))}
                </div>
            </div>

            <div class="detail-section">
                <h4>Montos</h4>
                <div class="detail-grid">
                    ${item('Monto AR$',   c.monto_pesos != null ? fmtMoney(c.monto_pesos) : '')}
                    ${item('Monto USD',   c.monto_usd   != null ? fmtMoney(c.monto_usd)   : '')}
                    ${item('Monto EUR',   c.monto_euros != null ? fmtMoney(c.monto_euros) : '')}
                    ${item('Monto otro',  c.monto_otro  != null ? fmtMoney(c.monto_otro)  : '')}
                    ${item('Moneda otro', escapeHtml(c.moneda_otro || ''))}
                    ${item('Caja BAS',    escapeHtml(c.caja_bas || ''))}
                    ${item('Resp. caja',  escapeHtml(c.resp_caja || ''))}
                </div>
            </div>

            <div class="detail-section">
                <h4>Observaciones</h4>
                <div>${escapeHtml(c.observaciones || '—').replace(/\n/g, '<br>')}</div>
            </div>
        </div>
    `;

    document.getElementById('btn-back').addEventListener('click', () => router.navigate('/contratos'));
    if (canEdit) document.getElementById('btn-edit').addEventListener('click', () => router.navigate(`/contratos/${id}/editar`));
    if (canDelete && c.estado_id !== 4) {
        document.getElementById('btn-del').addEventListener('click', async () => {
            const ok = await confirmDialog({
                title: 'Dar de baja contrato',
                message: `¿Confirma marcar el contrato #${id} como "Sin efecto"?`,
                confirmText: 'Dar de baja',
                danger: true,
            });
            if (ok) {
                try {
                    await api.del(`/contratos/${id}`);
                    toast.success('Contrato dado de baja.');
                    renderContratoDetail({ params });
                } catch (e) {
                    toast.error(e.message || 'Error al dar de baja.');
                }
            }
        });
    }
}
