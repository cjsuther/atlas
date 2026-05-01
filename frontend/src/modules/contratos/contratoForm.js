import { api } from '../../services/api.js';
import { renderShell, setPageTitle } from '../../components/layout.js';
import { toast } from '../../services/toast.js';
import { router } from '../../services/router.js';
import { escapeHtml } from '../../services/format.js';

export async function renderContratoForm({ params }) {
    const id = params.id ? Number(params.id) : null;
    const isEdit = !!id;
    const main = renderShell('contratos', isEdit ? 'Editar contrato' : 'Nuevo contrato');
    setPageTitle(isEdit ? `Editar contrato #${id}` : 'Nuevo contrato');

    main.innerHTML = '<div class="empty-state"><span class="loader dark"></span> Cargando...</div>';

    let contrato = {};
    if (isEdit) {
        try {
            const res = await api.get(`/contratos/${id}`);
            contrato = res.data || {};
        } catch (e) {
            toast.error('No se pudo cargar el contrato.');
            router.navigate('/contratos');
            return;
        }
    }

    // Cargar todas las opciones para los selects
    const [estados, tipos, solics, sectores, uvts, personal, contratosBase] = await Promise.all([
        api.get('/estados',         { per_page: 200 }).then(r => r.data || []),
        api.get('/tipos-contrato',  { per_page: 200 }).then(r => r.data || []),
        api.get('/solicitantes',    { per_page: 500 }).then(r => r.data || []),
        api.get('/sectores',        { per_page: 500 }).then(r => r.data || []),
        api.get('/uvt',             { per_page: 200 }).then(r => r.data || []),
        api.get('/personal',        { per_page: 1000 }).then(r => r.data || []),
        api.get('/contratos',       { per_page: 200 }).then(r => r.data || []),
    ]);

    const sel = (items, valKey, labelFn, current) => `
        <option value="">— Seleccionar —</option>
        ${items.map(i => {
            const v = i[valKey];
            const sel = String(current ?? '') === String(v) ? 'selected' : '';
            const label = typeof labelFn === 'function' ? labelFn(i) : i[labelFn];
            return `<option value="${v}" ${sel}>${escapeHtml(label)}</option>`;
        }).join('')}
    `;

    main.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h1 class="page-title">${isEdit ? 'Editar contrato' : 'Nuevo contrato'}</h1>
                <p class="page-subtitle">${isEdit ? `ID #${id}` : 'Carga los datos del nuevo contrato'}</p>
            </div>
            <div>
                <button class="btn btn-secondary" id="btn-cancel">Cancelar</button>
                <button class="btn btn-primary" id="btn-save">${isEdit ? 'Guardar cambios' : 'Crear contrato'}</button>
            </div>
        </div>

        <form id="contrato-form" class="card">

            <h4 style="color:var(--color-primary);margin-top:0;">Datos generales</h4>
            <div class="form-grid">
                <div class="field full">
                    <label>Nombre del proyecto *</label>
                    <input type="text" name="nombre_proy" class="input" required value="${escapeHtml(contrato.nombre_proy || '')}">
                </div>
                <div class="field">
                    <label>Estado</label>
                    <select name="estado_id" class="select">
                        ${sel(estados, 'estado_id', 'estado_nombre', contrato.estado_id)}
                    </select>
                </div>
                <div class="field">
                    <label>Tipo de contrato</label>
                    <select name="tipo_de_contrato_id" class="select">
                        ${sel(tipos, 'id_tipo', t => `${t.tipo} — ${t.nombre}`, contrato.tipo_de_contrato_id)}
                    </select>
                </div>
                <div class="field">
                    <label>Expediente</label>
                    <input type="text" name="expediente" class="input" value="${escapeHtml(contrato.expediente || '')}">
                </div>
                <div class="field">
                    <label>Fecha de expediente</label>
                    <input type="date" name="fecha_expediente" class="input" value="${contrato.fecha_expediente || ''}">
                </div>
                <div class="field full">
                    <label>Solicitud / sector GDE</label>
                    <input type="text" name="solicitud_sector_gde" class="input" value="${escapeHtml(contrato.solicitud_sector_gde || '')}">
                </div>
                <div class="field">
                    <label>Contrato dependencia (padre)</label>
                    <select name="dependencia_contractual_id" class="select">
                        <option value="">— Ninguno —</option>
                        ${contratosBase.filter(c => c.id_cto !== id).map(c => `
                            <option value="${c.id_cto}" ${String(contrato.dependencia_contractual_id) === String(c.id_cto) ? 'selected' : ''}>
                                #${c.id_cto} — ${escapeHtml((c.nombre_proy || '').slice(0, 80))}
                            </option>
                        `).join('')}
                    </select>
                </div>
                <div class="field">
                    <label>ID operatoria</label>
                    <input type="number" name="operatoria_id" class="input" value="${contrato.operatoria_id ?? ''}">
                </div>
                <div class="field full">
                    <label>Descripción del objeto</label>
                    <textarea name="descripcion_objeto" class="textarea">${escapeHtml(contrato.descripcion_objeto || '')}</textarea>
                </div>
                <div class="field full">
                    <label>Observaciones</label>
                    <textarea name="observaciones" class="textarea">${escapeHtml(contrato.observaciones || '')}</textarea>
                </div>
            </div>

            <h4 style="color:var(--color-primary);">Partes y ubicación</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Solicitante</label>
                    <select name="solicitante_id" class="select">
                        ${sel(solics, 'solicitante_id', 'razon_social', contrato.solicitante_id)}
                    </select>
                </div>
                <div class="field">
                    <label>UVT</label>
                    <select name="uvt_id" class="select">
                        ${sel(uvts, 'uvt_id', t => `${t.siglas} — ${t.nombre}`, contrato.uvt_id)}
                    </select>
                </div>
                <div class="field">
                    <label>Sector</label>
                    <select name="sector_id" class="select">
                        ${sel(sectores, 'sector_id', 'nombre', contrato.sector_id)}
                    </select>
                </div>
                <div class="field">
                    <label>Gerencia</label>
                    <input type="text" name="gerencia" class="input" value="${escapeHtml(contrato.gerencia || '')}">
                </div>
                <div class="field">
                    <label>Gerencia / área</label>
                    <input type="text" name="gerencia_area" class="input" value="${escapeHtml(contrato.gerencia_area || '')}">
                </div>
                <div class="field">
                    <label>Responsable 1</label>
                    <select name="resp1_id" class="select">
                        ${sel(personal, 'legajo', p => `${p.legajo} — ${p.apellido}, ${p.nombre}`, contrato.resp1_id)}
                    </select>
                </div>
                <div class="field">
                    <label>Responsable 2</label>
                    <select name="resp2_id" class="select">
                        ${sel(personal, 'legajo', p => `${p.legajo} — ${p.apellido}, ${p.nombre}`, contrato.resp2_id)}
                    </select>
                </div>
            </div>

            <h4 style="color:var(--color-primary);">Fechas y plazos</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Fecha de firma</label>
                    <input type="date" name="fecha_firma" class="input" value="${contrato.fecha_firma || ''}">
                </div>
                <div class="field">
                    <label>Fecha de inicio</label>
                    <input type="date" name="fecha_inicio" class="input" value="${contrato.fecha_inicio || ''}">
                </div>
                <div class="field">
                    <label>Fecha de vencimiento</label>
                    <input type="date" name="fecha_vencimiento" class="input" value="${contrato.fecha_vencimiento || ''}">
                </div>
                <div class="field">
                    <label>Fecha de finalización</label>
                    <input type="date" name="fecha_finalizado" class="input" value="${contrato.fecha_finalizado || ''}">
                </div>
                <div class="field">
                    <label>Duración (meses)</label>
                    <input type="number" min="0" name="duracion_meses" class="input" value="${contrato.duracion_meses ?? ''}">
                </div>
                <div class="field">
                    <label>Atraso (meses)</label>
                    <input type="number" min="0" name="atraso_meses" class="input" value="${contrato.atraso_meses ?? ''}">
                </div>
                <div class="field">
                    <label>Prórroga</label>
                    <select name="prorroga" class="select">
                        <option value="0" ${!contrato.prorroga ? 'selected' : ''}>No</option>
                        <option value="1" ${contrato.prorroga ? 'selected' : ''}>Sí</option>
                    </select>
                </div>
                <div class="field">
                    <label>Renovación automática</label>
                    <select name="renovacion_automatica" class="select">
                        <option value="0" ${!contrato.renovacion_automatica ? 'selected' : ''}>No</option>
                        <option value="1" ${contrato.renovacion_automatica ? 'selected' : ''}>Sí</option>
                    </select>
                </div>
                <div class="field full">
                    <label>Acta de finalización (referencia)</label>
                    <input type="text" name="acta_finalizacion" class="input" value="${escapeHtml(contrato.acta_finalizacion || '')}">
                </div>
            </div>

            <h4 style="color:var(--color-primary);">Montos</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Monto en pesos (AR$)</label>
                    <input type="number" step="0.01" min="0" name="monto_pesos" class="input" value="${contrato.monto_pesos ?? ''}">
                </div>
                <div class="field">
                    <label>Monto en USD</label>
                    <input type="number" step="0.01" min="0" name="monto_usd" class="input" value="${contrato.monto_usd ?? ''}">
                </div>
                <div class="field">
                    <label>Monto en EUR</label>
                    <input type="number" step="0.01" min="0" name="monto_euros" class="input" value="${contrato.monto_euros ?? ''}">
                </div>
                <div class="field">
                    <label>Monto otra moneda</label>
                    <input type="number" step="0.01" min="0" name="monto_otro" class="input" value="${contrato.monto_otro ?? ''}">
                </div>
                <div class="field">
                    <label>Moneda otra</label>
                    <input type="text" name="moneda_otro" class="input" value="${escapeHtml(contrato.moneda_otro || '')}">
                </div>
                <div class="field">
                    <label>Caja BAS</label>
                    <input type="text" name="caja_bas" class="input" value="${escapeHtml(contrato.caja_bas || '')}">
                </div>
                <div class="field">
                    <label>Responsable de caja</label>
                    <input type="text" name="resp_caja" class="input" value="${escapeHtml(contrato.resp_caja || '')}">
                </div>
            </div>
        </form>
    `;

    document.getElementById('btn-cancel').addEventListener('click', () => {
        router.navigate(isEdit ? `/contratos/${id}` : '/contratos');
    });

    document.getElementById('btn-save').addEventListener('click', async () => {
        const form = document.getElementById('contrato-form');
        const fd = new FormData(form);
        const data = {};
        for (const [k, v] of fd.entries()) {
            if (v === '') data[k] = null;
            else if (['prorroga','renovacion_automatica','automatico_ejecucion','automatico_finalizado'].includes(k)) data[k] = v === '1';
            else data[k] = v;
        }

        if (!data.nombre_proy || !String(data.nombre_proy).trim()) {
            toast.error('El nombre del proyecto es obligatorio.');
            return;
        }

        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        btn.innerHTML = '<span class="loader"></span> Guardando...';

        try {
            const res = isEdit
                ? await api.put(`/contratos/${id}`, data)
                : await api.post('/contratos', data);
            toast.success(isEdit ? 'Contrato actualizado.' : 'Contrato creado.');
            const newId = res.data?.id_cto || id;
            router.navigate(`/contratos/${newId}`);
        } catch (e) {
            btn.disabled = false;
            btn.textContent = isEdit ? 'Guardar cambios' : 'Crear contrato';

            if (e.data && e.data.errors) {
                const msgs = Object.values(e.data.errors).flat();
                toast.error(msgs[0] || 'Errores de validación.');
            } else {
                toast.error(e.message || 'Error al guardar.');
            }
        }
    });
}
