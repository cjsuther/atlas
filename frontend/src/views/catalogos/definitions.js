/** Etiquetas de los niveles del árbol de la estructura. */
const NIVELES = {
    organizacion:  'Toda la organización',
    gerencia_area: 'Gerencia de Área',
    gerencia:      'Gerencia',
    contrato:      'Contrato',
};

/**
 * Definiciones de cada catálogo para el ABM genérico:
 *   - title, endpoint slug, keyField
 *   - columns: [{ key, label, render? }]
 *   - formFields: [{ name, label, type, required?, max?, options?, ... }]
 */
export const ENTITY_DEFS = {
    'tipos-contrato-ejecucion': {
        title: 'Tipos de expediente',
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
        title: 'Estados de expediente',
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
    'cuentas-operativas': {
        title: 'Cuentas Operativas',
        subtitle: 'Cada cuenta cuelga de un nodo de la estructura —Gerencia de Área, Gerencia o '
                + 'Contrato— y a ella se imputan los expedientes.',
        endpoint: 'cuentas-operativas',
        keyField: 'id',
        columns: [
            { key: 'id',          label: 'ID' },
            { key: 'nombre',      label: 'Nombre' },
            { key: 'nivel',       label: 'Nivel', render: (r) => NIVELES[r.nivel] || r.nivel },
            { key: 'ruta',        label: 'Ubicación en la estructura' },
            { key: 'descripcion', label: 'Descripción' },
            { key: 'activo',      label: 'Activa', render: (r) => (r.activo ? 'Sí' : 'No') },
        ],
        formFields: [
            { name: 'nombre',      label: 'Nombre', type: 'text', required: true, max: 200 },
            { name: 'sector_id',   label: 'Cuelga de', required: true,
              type: 'select-async', endpoint: 'sectores', valueKey: 'sector_id', labelKey: 'nombre' },
            { name: 'descripcion', label: 'Descripción', type: 'textarea', max: 500 },
            { name: 'activo',      label: 'Activa', type: 'checkbox' },
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
            { key: 'lugar_trabajo',  label: 'Lugar de trabajo', render: (r) => r.lugar_trabajo?.nombre || '—' },
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
