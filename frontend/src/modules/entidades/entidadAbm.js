/**
 * ABM genérico para entidades maestras. Cada entidad define:
 *  - endpoint
 *  - keyField
 *  - columns: [{ key, label, render? }]
 *  - formFields: [{ name, label, type, required?, options?, ... }]
 *
 * Solo el rol admin puede ABM, los demás roles ven la tabla en read-only.
 */
import { api, auth } from '../../services/api.js';
import { renderShell, setPageTitle } from '../../components/layout.js';
import { renderPager } from '../../components/pager.js';
import { openModal, confirmDialog } from '../../components/modal.js';
import { toast } from '../../services/toast.js';
import { debounce, escapeHtml } from '../../services/format.js';

export const ENTITY_DEFS = {
    estados: {
        title: 'Estados de contrato',
        navId: 'estados',
        endpoint: 'estados',
        keyField: 'estado_id',
        columns: [
            { key: 'estado_id',     label: 'ID' },
            { key: 'estado_nombre', label: 'Nombre' },
            { key: 'descripcion',   label: 'Descripción' },
        ],
        formFields: [
            { name: 'estado_nombre', label: 'Nombre',      type: 'text', required: true, max: 100 },
            { name: 'descripcion',   label: 'Descripción', type: 'textarea' },
        ],
    },
    'tipos-contrato': {
        title: 'Tipos de contrato',
        navId: 'tipos-contrato',
        endpoint: 'tipos-contrato',
        keyField: 'id_tipo',
        columns: [
            { key: 'id_tipo', label: 'ID' },
            { key: 'tipo',    label: 'Sigla' },
            { key: 'nombre',  label: 'Descripción' },
        ],
        formFields: [
            { name: 'tipo',   label: 'Sigla',       type: 'text', required: true, max: 20 },
            { name: 'nombre', label: 'Descripción', type: 'text', required: true, max: 200 },
        ],
    },
    solicitantes: {
        title: 'Solicitantes',
        navId: 'solicitantes',
        endpoint: 'solicitantes',
        keyField: 'solicitante_id',
        columns: [
            { key: 'solicitante_id', label: 'ID' },
            { key: 'razon_social',   label: 'Razón social' },
            { key: 'cuil_cuit',      label: 'CUIT/CUIL' },
            { key: 'rubro',          label: 'Rubro' },
            { key: 'localizacion',   label: 'Localización' },
            { key: 'nombre_contacto',label: 'Contacto' },
        ],
        formFields: [
            { name: 'razon_social',    label: 'Razón social',  type: 'text', required: true, max: 300 },
            { name: 'cuil_cuit',       label: 'CUIT/CUIL',     type: 'text', max: 20 },
            { name: 'rubro',           label: 'Rubro',         type: 'text', max: 200 },
            { name: 'localizacion',    label: 'Localización',  type: 'text', max: 300 },
            { name: 'telefono',        label: 'Teléfono',      type: 'text', max: 100 },
            { name: 'nombre_contacto', label: 'Contacto',      type: 'text', max: 200 },
        ],
    },
    sectores: {
        title: 'Sectores',
        navId: 'sectores',
        endpoint: 'sectores',
        keyField: 'sector_id',
        columns: [
            { key: 'sector_id',  label: 'ID' },
            { key: 'nombre',     label: 'Nombre' },
            { key: 'dependencia',label: 'Depende de', render: (r) => r.dependencia ? escapeHtml(r.dependencia.nombre) : '—' },
            { key: 'responsable',label: 'Responsable' },
            { key: 'ubicacion',  label: 'Ubicación' },
        ],
        formFields: [
            { name: 'nombre',         label: 'Nombre',       type: 'text', required: true, max: 200 },
            { name: 'dependencia_id', label: 'Depende de',   type: 'select-async', endpoint: 'sectores', valueKey: 'sector_id', labelKey: 'nombre', allowEmpty: true },
            { name: 'responsable',    label: 'Responsable',  type: 'text', max: 200 },
            { name: 'web',            label: 'Web',          type: 'text', max: 300 },
            { name: 'ubicacion',      label: 'Ubicación',    type: 'text', max: 200 },
        ],
    },
    utt: {
        title: 'UTTs',
        navId: 'utt',
        endpoint: 'utt',
        keyField: 'utt_id',
        columns: [
            { key: 'utt_id',       label: 'ID' },
            { key: 'denominacion', label: 'Sigla' },
            { key: 'nombre',       label: 'Nombre' },
        ],
        formFields: [
            { name: 'denominacion', label: 'Sigla',  type: 'text', required: true, max: 50 },
            { name: 'nombre',       label: 'Nombre', type: 'text', required: true, max: 300 },
        ],
    },
    uvt: {
        title: 'UVTs',
        navId: 'uvt',
        endpoint: 'uvt',
        keyField: 'uvt_id',
        columns: [
            { key: 'uvt_id',      label: 'ID' },
            { key: 'siglas',      label: 'Siglas' },
            { key: 'nombre',      label: 'Nombre' },
            { key: 'responsable', label: 'Responsable' },
        ],
        formFields: [
            { name: 'siglas',      label: 'Siglas',     type: 'text', required: true, max: 50 },
            { name: 'nombre',      label: 'Nombre',     type: 'text', required: true, max: 300 },
            { name: 'responsable', label: 'Responsable', type: 'text', max: 200 },
        ],
    },
    personal: {
        title: 'Personal',
        navId: 'personal',
        endpoint: 'personal',
        keyField: 'legajo',
        columns: [
            { key: 'legajo',   label: 'Legajo' },
            { key: 'apellido', label: 'Apellido' },
            { key: 'nombre',   label: 'Nombre' },
            { key: 'mail',     label: 'E-mail' },
            { key: 'interno',  label: 'Interno' },
            { key: 'lugar_trabajo', label: 'Lugar de trabajo', render: (r) => r.lugar_trabajo ? escapeHtml(r.lugar_trabajo.nombre) : '—' },
        ],
        formFields: [
            { name: 'legajo',           label: 'Legajo',          type: 'number', required: true, requiredOnCreate: true, onlyOnCreate: true },
            { name: 'apellido',         label: 'Apellido',        type: 'text', required: true, max: 100 },
            { name: 'nombre',           label: 'Nombre',          type: 'text', required: true, max: 100 },
            { name: 'interno',          label: 'Interno',         type: 'text', max: 20 },
            { name: 'mail',             label: 'E-mail',          type: 'email', max: 200 },
            { name: 'lugar_trabajo_id', label: 'Lugar de trabajo', type: 'select-async', endpoint: 'sectores', valueKey: 'sector_id', labelKey: 'nombre', allowEmpty: true },
        ],
    },
};

export async function renderEntidadAbm({ params }) {
    const slug = params.slug;
    const def = ENTITY_DEFS[slug];
    if (!def) {
        document.getElementById('app').innerHTML = '<div class="empty-state">Catálogo no encontrado.</div>';
        return;
    }

    const main = renderShell(def.navId, def.title);
    setPageTitle(def.title);

    const canEdit = auth.hasRole('admin');

    let state = { page: 1, per_page: 20, search: '' };

    main.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">${escapeHtml(def.title)}</h1>
                <p class="page-subtitle">Listado y gestión del catálogo</p>
            </div>
            <div>
                ${canEdit ? `<button class="btn btn-primary" id="btn-new">+ Nuevo</button>` : ''}
            </div>
        </div>

        <div class="toolbar">
            <div class="search-box">
                <input type="text" class="input" id="search" placeholder="Buscar...">
            </div>
        </div>

        <div id="table-area"><div class="empty-state"><span class="loader dark"></span> Cargando...</div></div>
        <div id="pager"></div>
    `;

    document.getElementById('search').addEventListener('input', debounce(e => {
        state.search = e.target.value;
        state.page = 1;
        load();
    }, 300));

    if (canEdit) {
        document.getElementById('btn-new').addEventListener('click', () => openForm(def, null, load));
    }

    async function load() {
        const tableArea = document.getElementById('table-area');
        tableArea.innerHTML = '<div class="empty-state"><span class="loader dark"></span> Cargando...</div>';
        try {
            const data = await api.get(`/${def.endpoint}`, state);
            renderEntityTable(tableArea, def, data, load, canEdit);
            renderPager(document.getElementById('pager'), data, p => { state.page = p; load(); });
        } catch (e) {
            tableArea.innerHTML = `<div class="empty-state">Error: ${escapeHtml(e.message)}</div>`;
        }
    }

    load();
}

function renderEntityTable(container, def, paginator, reload, canEdit) {
    const items = paginator.data || [];
    if (items.length === 0) {
        container.innerHTML = '<div class="empty-state">No se encontraron registros.</div>';
        return;
    }

    container.innerHTML = `
        <div class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        ${def.columns.map(c => `<th>${escapeHtml(c.label)}</th>`).join('')}
                        ${canEdit ? `<th style="text-align:right;">Acciones</th>` : ''}
                    </tr>
                </thead>
                <tbody>
                    ${items.map(r => `
                        <tr>
                            ${def.columns.map(c => `<td>${c.render ? c.render(r) : escapeHtml(r[c.key] ?? '')}</td>`).join('')}
                            ${canEdit ? `
                                <td>
                                    <div class="actions">
                                        <button data-act="edit" data-id="${r[def.keyField]}">Editar</button>
                                        <button class="danger" data-act="del" data-id="${r[def.keyField]}">Eliminar</button>
                                    </div>
                                </td>
                            ` : ''}
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;

    if (canEdit) {
        container.querySelectorAll('button[data-act]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                if (btn.dataset.act === 'edit') {
                    const item = items.find(i => String(i[def.keyField]) === String(id));
                    openForm(def, item, reload);
                } else if (btn.dataset.act === 'del') {
                    const ok = await confirmDialog({
                        title: 'Eliminar registro',
                        message: '¿Confirma la eliminación? Si tiene dependencias, la operación se cancelará.',
                        confirmText: 'Eliminar',
                        danger: true,
                    });
                    if (ok) {
                        try {
                            await api.del(`/${def.endpoint}/${id}`);
                            toast.success('Registro eliminado.');
                            reload();
                        } catch (e) {
                            if (e.status === 409 && e.data?.detalles) {
                                toast.error(`No se puede eliminar: ${e.data.detalles.join(' ')}`, 6000);
                            } else {
                                toast.error(e.message || 'Error al eliminar.');
                            }
                        }
                    }
                }
            });
        });
    }
}

async function openForm(def, item, reload) {
    const isEdit = !!item;
    const id = isEdit ? item[def.keyField] : null;

    const body = document.createElement('form');
    body.innerHTML = '<div class="form-grid" id="fields"></div>';

    const m = openModal({
        title: isEdit ? `Editar ${def.title.toLowerCase().replace(/s$/, '')}` : `Nuevo registro`,
        body,
    });

    const fieldsEl = body.querySelector('#fields');

    // Renderizar campos
    for (const f of def.formFields) {
        if (isEdit && f.onlyOnCreate) continue;
        const wrap = document.createElement('div');
        wrap.className = 'field' + (f.full ? ' full' : '');
        const inputId = `f_${f.name}`;
        let inputHtml = '';
        const value = item ? (item[f.name] ?? '') : '';

        if (f.type === 'textarea') {
            inputHtml = `<textarea id="${inputId}" name="${f.name}" class="textarea" ${f.required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
        } else if (f.type === 'select-async') {
            inputHtml = `<select id="${inputId}" name="${f.name}" class="select"></select>`;
        } else {
            const t = f.type === 'email' ? 'email' : (f.type === 'number' ? 'number' : 'text');
            const max = f.max ? `maxlength="${f.max}"` : '';
            inputHtml = `<input id="${inputId}" name="${f.name}" type="${t}" class="input" ${max} value="${escapeHtml(value)}" ${f.required ? 'required' : ''}>`;
        }

        wrap.innerHTML = `
            <label for="${inputId}">${escapeHtml(f.label)}${f.required ? ' *' : ''}</label>
            ${inputHtml}
        `;
        fieldsEl.appendChild(wrap);

        if (f.type === 'select-async') {
            // Cargar opciones
            const sel = wrap.querySelector('select');
            sel.innerHTML = `<option value="">${f.allowEmpty ? '— Sin asignar —' : '— Seleccionar —'}</option>`;
            try {
                const data = await api.get(`/${f.endpoint}`, { per_page: 500 });
                (data.data || []).forEach(opt => {
                    const optEl = document.createElement('option');
                    optEl.value = opt[f.valueKey];
                    optEl.textContent = typeof f.labelKey === 'function' ? f.labelKey(opt) : opt[f.labelKey];
                    if (item && String(item[f.name] ?? '') === String(optEl.value)) optEl.selected = true;
                    sel.appendChild(optEl);
                });
            } catch (e) { /* silencioso */ }
        }
    }

    // Footer con botones
    const btnCancel = document.createElement('button');
    btnCancel.className = 'btn btn-secondary';
    btnCancel.textContent = 'Cancelar';
    btnCancel.type = 'button';
    btnCancel.onclick = () => m.close();

    const btnSave = document.createElement('button');
    btnSave.className = 'btn btn-primary';
    btnSave.textContent = isEdit ? 'Guardar' : 'Crear';
    btnSave.type = 'button';

    m.footerEl.appendChild(btnCancel);
    m.footerEl.appendChild(btnSave);
    m.footerEl.style.display = '';

    btnSave.onclick = async () => {
        const fd = new FormData(body);
        const data = {};
        for (const [k, v] of fd.entries()) data[k] = v === '' ? null : v;

        // Validación mínima frontend
        for (const f of def.formFields) {
            if (isEdit && f.onlyOnCreate) continue;
            if (f.required && !data[f.name]) {
                toast.error(`El campo "${f.label}" es requerido.`);
                return;
            }
        }

        btnSave.disabled = true;
        btnSave.innerHTML = '<span class="loader"></span> Guardando...';

        try {
            if (isEdit) await api.put(`/${def.endpoint}/${id}`, data);
            else        await api.post(`/${def.endpoint}`, data);
            toast.success(isEdit ? 'Actualizado.' : 'Creado.');
            m.close();
            reload();
        } catch (e) {
            btnSave.disabled = false;
            btnSave.textContent = isEdit ? 'Guardar' : 'Crear';
            if (e.data && e.data.errors) {
                const msgs = Object.values(e.data.errors).flat();
                toast.error(msgs[0] || 'Error de validación.');
            } else {
                toast.error(e.message || 'Error al guardar.');
            }
        }
    };
}
