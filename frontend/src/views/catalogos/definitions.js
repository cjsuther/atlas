import { markRaw } from 'vue';
import { fmtMoney } from '@/composables/useFormat';
import ContratoArchivos from '@/components/ContratoArchivos.vue';

/** Etiquetas de los niveles del árbol de la estructura. */
const NIVELES = {
    organizacion:  'Toda la organización',
    gerencia_area: 'Gerencia de Área',
    gerencia:      'Gerencia',
    contrato:      'Contrato',
};

/** Símbolo de cada moneda del contrato. */
const SIMBOLOS = { 'Peso': '$', 'Dólar': 'US$', 'Euro': '€' };

/** Una fecha de la base (AAAA-MM-DD) como DD/MM/AAAA, sin pasar por la zona horaria. */
function fecha(v) {
    if (!v) return '—';
    const [a, m, d] = String(v).slice(0, 10).split('-');
    return `${d}/${m}/${a}`;
}

const persona = (p) => [p.apellido, p.nombre].filter(Boolean).join(', ');

/**
 * Definiciones de cada catálogo para el ABM genérico:
 *   - title, endpoint slug, keyField
 *   - columns: [{ key, label, render? }]
 *   - formFields: [{ name, label, type, required?, max?, options?, ... }]
 */
export const ENTITY_DEFS = {
    'tipos-contrato-ejecucion': {
        title: 'Tipos de contrato',
        endpoint: 'tipos-contrato-ejecucion',
        keyField: 'id',
        columns: [
            { key: 'id',     label: 'ID' },
            { key: 'sigla',  label: 'Sigla' },
            { key: 'nombre', label: 'Nombre' },
        ],
        formFields: [
            { name: 'sigla',  label: 'Sigla',  type: 'text', required: true, max: 20 },
            { name: 'nombre', label: 'Nombre', type: 'text', required: true, max: 200 },
        ],
    },
    'estados-ejecucion': {
        title: 'Estados de contrato',
        endpoint: 'estados-ejecucion',
        keyField: 'id',
        columns: [
            { key: 'id',          label: 'ID' },
            { key: 'nombre',      label: 'Nombre' },
            { key: 'descripcion', label: 'Descripción' },
        ],
        formFields: [
            { name: 'nombre',      label: 'Nombre',      type: 'text', required: true, max: 100 },
            { name: 'descripcion', label: 'Descripción', type: 'textarea' },
        ],
    },
    'solicitantes': {
        title: 'Solicitantes',
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
            { name: 'razon_social',    label: 'Razón social', type: 'text', required: true, max: 300 },
            { name: 'cuil_cuit',       label: 'CUIT/CUIL',    type: 'text', max: 20 },
            { name: 'rubro',           label: 'Rubro',        type: 'text', max: 200 },
            { name: 'localizacion',    label: 'Localización', type: 'text', max: 300 },
            { name: 'telefono',        label: 'Teléfono',     type: 'text', max: 100 },
            { name: 'nombre_contacto', label: 'Contacto',     type: 'text', max: 200 },
        ],
    },
    'sectores': {
        title: 'Estructura',
        subtitle: 'El árbol tiene tres niveles: Gerencia de Área, Gerencia y Contrato. '
                + 'Los nodos que no dependen de ningún otro son las Gerencias de Área, que '
                + 'definen el límite de confidencialidad.',
        endpoint: 'sectores',
        keyField: 'sector_id',
        columns: [
            { key: 'sector_id',   label: 'ID' },
            { key: 'nombre',      label: 'Nombre' },
            { key: 'nivel',       label: 'Nivel',
              render: (r) => NIVELES[r.nivel] || (r.es_gerencia_area ? 'Gerencia de Área' : 'Gerencia') },
            { key: 'dependencia', label: 'Depende de', render: (r) => r.dependencia?.nombre || '—' },
            { key: 'responsable', label: 'Responsable' },
            { key: 'ubicacion',   label: 'Ubicación' },
        ],
        formFields: [
            { name: 'nombre',         label: 'Nombre',      type: 'text', required: true, max: 200 },
            { name: 'dependencia_id', label: 'Depende de (vacío = Gerencia de Área)',

              type: 'select-async', endpoint: 'sectores', valueKey: 'sector_id', labelKey: 'nombre', allowEmpty: true },
            { name: 'responsable',    label: 'Responsable', type: 'text', max: 200 },
            { name: 'web',            label: 'Web',         type: 'text', max: 300 },
            { name: 'ubicacion',      label: 'Ubicación',   type: 'text', max: 200 },
        ],
    },
    'contratos': {
        title: 'Contratos',
        subtitle: 'La ficha de cada Contrato, el tercer nivel de la estructura. Los contratos se '
                + 'crean y se borran desde Estructura; acá se completan sus datos y se adjuntan sus '
                + 'archivos. Si el monto no está en pesos, la cotización lo lleva a pesos.',
        endpoint: 'contratos',
        keyField: 'sector_id',
        sinAlta: true,
        sinBaja: true,
        editable: (r) => !!r.editable,
        // Desde el nombre se ve la ficha; para cambiarla está el botón de editar.
        abrirEnConsulta: true,
        tituloFila: (r) => r.nombre,
        // Dentro de la ficha, los archivos del contrato: se suben y se quitan en el momento.
        panelExtra: markRaw(ContratoArchivos),
        modalGrande: true,
        columns: [
            { key: 'nombre',            label: 'Contrato' },
            { key: 'gerencia',          label: 'Gerencia',
              render: (r) => [r.gerencia_area, r.gerencia].filter(Boolean).join(' › ') },
            { key: 'tipo',              label: 'Tipo',   render: (r) => r.tipo || '—' },
            { key: 'estado',            label: 'Estado', render: (r) => r.estado || '—' },
            { key: 'uvt',               label: 'UVT',    render: (r) => r.uvt || '—' },
            { key: 'fecha_vencimiento', label: 'Vence',  render: (r) => fecha(r.fecha_vencimiento) },
            { key: 'monto',             label: 'Monto',
              render: (r) => (r.monto === null ? '—' : `${SIMBOLOS[r.moneda] || ''} ${fmtMoney(r.monto)}`) },
            { key: 'monto_pesos',       label: 'Monto en pesos',
              render: (r) => (r.monto_pesos === null ? '—' : fmtMoney(r.monto_pesos)) },
            { key: 'caja_bas',          label: 'Caja BAS', render: (r) => r.caja_bas || '—' },
            { key: 'archivos',          label: 'Archivos', render: (r) => (r.archivos ? `📎 ${r.archivos}` : '—') },
        ],
        formFields: [
            { name: 'tipo_contrato_id', label: 'Tipo', type: 'select-async',
              endpoint: 'tipos-contrato-ejecucion', valueKey: 'id', labelKey: (o) => `${o.sigla} — ${o.nombre}` },
            { name: 'estado_id',        label: 'Estado', type: 'select-async',
              endpoint: 'estados-ejecucion', valueKey: 'id', labelKey: 'nombre' },
            { name: 'uvt_id',           label: 'UVT', type: 'select-async',
              endpoint: 'uvt', valueKey: 'uvt_id', labelKey: (o) => `${o.siglas} — ${o.nombre}` },
            { name: 'solicitante_id',   label: 'Solicitante', type: 'select-async',
              endpoint: 'solicitantes', valueKey: 'solicitante_id', labelKey: 'razon_social' },
            { name: 'resp1_id',         label: 'Responsable 1', type: 'select-async',
              endpoint: 'personal', valueKey: 'legajo', labelKey: persona },
            { name: 'resp2_id',         label: 'Responsable 2', type: 'select-async',
              endpoint: 'personal', valueKey: 'legajo', labelKey: persona },
            { name: 'cliente',          label: 'Cliente',  type: 'text', max: 300 },
            { name: 'caja_bas',         label: 'Caja BAS', type: 'text', max: 200 },
            { name: 'fecha_inicio',       label: 'Fecha de inicio',       type: 'date' },
            { name: 'fecha_vencimiento',  label: 'Fecha de vencimiento',  type: 'date' },
            { name: 'fecha_finalizacion', label: 'Fecha de finalización', type: 'date' },
            { name: 'acta_finalizacion',  label: 'Acta de finalización',  type: 'text', max: 500 },
            { name: 'prorroga',              label: 'Prórroga',               type: 'checkbox', default: false },
            { name: 'renovacion_automatica', label: 'Renovación automática',  type: 'checkbox', default: false },
            { name: 'monto',      label: 'Monto', type: 'number', step: '0.01' },
            { name: 'moneda',     label: 'Moneda', type: 'select', required: true, default: 'Peso',
              options: [
                  { value: 'Peso',  label: 'Pesos' },
                  { value: 'Dólar', label: 'Dólares' },
                  { value: 'Euro',  label: 'Euros' },
              ] },
            { name: 'cotizacion', label: 'Cotización', type: 'number', step: '0.0001',
              required: (d) => d.moneda !== 'Peso',
              deshabilitado: (d) => d.moneda === 'Peso',
              hint: (d) => {
                  if (d.moneda === 'Peso') return 'No aplica: el monto ya está en pesos.';
                  const m = Number(d.monto), c = Number(d.cotizacion);
                  return d.monto !== '' && d.monto !== null && c > 0
                      ? `Equivale a $ ${fmtMoney(m * c)}.`
                      : `Pesos por cada ${d.moneda === 'Dólar' ? 'dólar' : 'euro'}.`;
              } },
            { name: 'descripcion_objeto', label: 'Objeto del contrato', type: 'textarea', full: true },
            { name: 'observaciones',      label: 'Observaciones',       type: 'textarea', full: true },
        ],
    },
    'cuentas-operativas': {
        title: 'Cuentas',
        subtitle: 'Cada cuenta cuelga de un nodo de la estructura —Gerencia de Área, Gerencia o '
                + 'Contrato—. En la cuenta vive el saldo: ahí se registran los ingresos, los gastos, '
                + 'las transferencias, los incentivos y la MCH.',
        endpoint: 'cuentas-operativas',
        keyField: 'id',
        columns: [
            { key: 'id',          label: 'ID' },
            { key: 'nombre',      label: 'Nombre',
              link: (r) => ({ name: 'cuenta-detalle', params: { id: r.id } }) },
            { key: 'nivel',       label: 'Nivel', render: (r) => NIVELES[r.nivel] || r.nivel },
            { key: 'saldo',       label: 'Saldo', render: (r) => fmtMoney(r.saldo) },
            { key: 'ruta',        label: 'Ubicación en la estructura' },
            { key: 'descripcion', label: 'Descripción' },
            { key: 'activo',      label: 'Activa', render: (r) => (r.activo ? 'Sí' : 'No') },
        ],
        formFields: [
            { name: 'nombre',        label: 'Nombre', type: 'text', required: true, max: 200 },
            { name: 'sector_id',     label: 'Cuelga de', required: true,
              type: 'select-async', endpoint: 'sectores', valueKey: 'sector_id', labelKey: 'nombre' },
            { name: 'saldo_inicial', label: 'Saldo inicial', type: 'number', step: '0.01' },
            { name: 'descripcion',   label: 'Descripción', type: 'textarea', max: 500 },
            { name: 'activo',        label: 'Activa', type: 'checkbox' },
        ],
    },
    'uvt': {
        title: 'UVTs',
        endpoint: 'uvt',
        keyField: 'uvt_id',
        columns: [
            { key: 'uvt_id',      label: 'ID' },
            { key: 'siglas',      label: 'Siglas' },
            { key: 'nombre',      label: 'Nombre' },
            { key: 'responsable', label: 'Responsable' },
        ],
        formFields: [
            { name: 'siglas',      label: 'Siglas',      type: 'text', required: true, max: 50 },
            { name: 'nombre',      label: 'Nombre',      type: 'text', required: true, max: 300 },
            { name: 'responsable', label: 'Responsable', type: 'text', max: 200 },
        ],
    },
    'personal': {
        title: 'Personal',
        endpoint: 'personal',
        keyField: 'legajo',
        columns: [
            { key: 'legajo',         label: 'Legajo' },
            { key: 'apellido',       label: 'Apellido' },
            { key: 'nombre',         label: 'Nombre' },
            { key: 'mail',           label: 'E-mail' },
            { key: 'interno',        label: 'Interno' },
            { key: 'lugar_trabajo',  label: 'Lugar de trabajo',
              render: (r) => r.lugar_trabajo?.nombre || '—' },
        ],
        formFields: [
            { name: 'legajo',           label: 'Legajo',          type: 'number', required: true, onlyOnCreate: true },
            { name: 'apellido',         label: 'Apellido',        type: 'text',   required: true, max: 100 },
            { name: 'nombre',           label: 'Nombre',          type: 'text',   required: true, max: 100 },
            { name: 'interno',          label: 'Interno',         type: 'text',   max: 20 },
            { name: 'mail',             label: 'E-mail',          type: 'email',  max: 200 },
            { name: 'lugar_trabajo_id', label: 'Lugar de trabajo', type: 'select-async', endpoint: 'sectores', valueKey: 'sector_id', labelKey: 'nombre', allowEmpty: true },
        ],
    },
};
