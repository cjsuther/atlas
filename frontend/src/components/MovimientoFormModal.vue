<template>
    <BaseModal v-model="open" :title="title" size="large">
        <form @submit.prevent="submit">
            <div class="form-grid">
                <!-- Acción: qué originó el movimiento -->
                <div class="field">
                    <label>Acción *</label>
                    <SelectBuscador v-model="data.accion" :deshabilitado="isEdit"
                                    :opciones="ACCIONES.map(a => ({ value: a.value, etiqueta: a.label }))" />
                    <div class="hint">{{ ayudaAccion }}</div>
                    <div v-if="errors.accion" class="error">{{ errors.accion[0] }}</div>
                </div>

                <!-- Tipo: signo del movimiento -->
                <div class="field">
                    <label>Tipo *</label>
                    <SelectBuscador v-model="data.tipo" :deshabilitado="isEdit || tipoFijo"
                                    :opciones="[{ value: 'ingreso', etiqueta: 'Ingreso' },
                                                { value: 'gasto', etiqueta: 'Gasto' }]" />
                    <div v-if="tipoFijo" class="hint">Los incentivos y la MCH se registran siempre como gasto.</div>
                    <div v-if="errors.tipo" class="error">{{ errors.tipo[0] }}</div>
                </div>

                <!-- Expediente: se elige de los ya cargados; si falta, se da de
                     alta sin salir de acá. -->
                <div class="field">
                    <label>Expediente *</label>
                    <div style="display:flex;gap:6px;align-items:flex-start;">
                        <div style="flex:1;min-width:0;">
                            <SelectBuscador v-model="data.expediente_id" :opciones="opcionesExpediente"
                                            placeholder="Elegir expediente…"
                                            placeholder-busqueda="Buscar por número de expediente…" />
                        </div>
                        <button type="button" class="btn btn-secondary" title="Dar de alta un expediente"
                                @click="nuevoExpediente">+ Nuevo</button>
                    </div>
                    <div v-if="errors.expediente_id" class="error">{{ errors.expediente_id[0] }}</div>
                </div>

                <!-- Contraparte: depende de la acción. No siempre hay cliente o
                     proveedor; a veces es otra cuenta y a veces sólo un rubro. -->
                <div v-if="contraparte === 'proveedor'" class="field">
                    <label>Proveedor *</label>
                    <input v-model="data.proveedor" class="input" required maxlength="300" />
                    <div v-if="errors.proveedor" class="error">{{ errors.proveedor[0] }}</div>
                </div>
                <div v-else-if="contraparte === 'cliente'" class="field">
                    <label>Cliente *</label>
                    <input v-model="data.cliente" class="input" required maxlength="300" />
                    <div v-if="errors.cliente" class="error">{{ errors.cliente[0] }}</div>
                </div>
                <div v-else-if="contraparte === 'cuenta'" class="field">
                    <label>Cuenta contraparte *</label>
                    <SelectBuscador v-model="data.cuenta_contraparte_id" :opciones="opcionesCuenta"
                                    placeholder="Elegir cuenta…"
                                    placeholder-busqueda="Buscar por nombre de cuenta o nodo…" />
                    <div class="hint">
                        Se registra automáticamente la contrapartida en la otra cuenta.
                    </div>
                    <div v-if="errors.cuenta_contraparte_id" class="error">{{ errors.cuenta_contraparte_id[0] }}</div>
                </div>
                <div v-else class="field">
                    <label>Rubro *</label>
                    <input v-model="data.rubro" class="input" required maxlength="200" />
                    <div v-if="errors.rubro" class="error">{{ errors.rubro[0] }}</div>
                </div>

                <!-- Moneda -->
                <div class="field">
                    <label>Moneda *</label>
                    <SelectBuscador v-model="data.moneda"
                                    :opciones="[{ value: 'Peso', etiqueta: 'Peso (ARS)' },
                                                { value: 'Dólar', etiqueta: 'Dólar (USD)' }]" />
                </div>

                <!-- Monto: en pesos si moneda=Peso; si Dólar, monto+cotización y el peso se calcula -->
                <template v-if="data.moneda === 'Peso'">
                    <div class="field">
                        <label>Monto (ARS) *</label>
                        <input v-model.number="data.monto" type="number" step="0.01" min="0" class="input" required />
                        <div v-if="errors.monto" class="error">{{ errors.monto[0] }}</div>
                    </div>
                </template>
                <template v-else>
                    <div class="field">
                        <label>Monto (USD) *</label>
                        <input v-model.number="data.monto_dolares" type="number" step="0.01" min="0" class="input" required />
                        <div v-if="errors.monto_dolares" class="error">{{ errors.monto_dolares[0] }}</div>
                    </div>
                    <div class="field">
                        <label>Cotización *</label>
                        <input v-model.number="data.cotizacion" type="number" step="0.0001" min="0" class="input" required />
                        <div v-if="errors.cotizacion" class="error">{{ errors.cotizacion[0] }}</div>
                    </div>
                    <div class="field full" style="font-size:12px;color:var(--color-muted);">
                        Monto en pesos calculado: <strong>${{ fmtMoney(montoPesosCalc) }}</strong>
                    </div>
                </template>

                <!-- Objeto -->
                <div class="field full">
                    <label>Objeto {{ data.tipo === 'gasto' ? 'del gasto' : 'del ingreso' }} *</label>
                    <textarea v-model="data.objeto" class="textarea" required />
                    <div v-if="errors.objeto" class="error">{{ errors.objeto[0] }}</div>
                </div>

                <!-- Factura (sólo gastos por factura) -->
                <div v-if="admiteFactura" class="field full">
                    <label>Factura (opcional) · PDF / JPG / PNG, máx. 10 MB</label>
                    <div v-if="currentFactura && !eliminarFactura && !archivo"
                         style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:13px;">📎 {{ currentFactura }}</span>
                        <button type="button" class="btn btn-secondary btn-sm" @click="descargarFactura">Descargar</button>
                        <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-danger);"
                                @click="eliminarFactura = true">Quitar</button>
                    </div>
                    <div v-if="eliminarFactura" style="font-size:12px;color:var(--color-danger);margin-bottom:6px;">
                        Se eliminará la factura actual al guardar.
                        <button type="button" class="btn btn-ghost btn-sm" @click="eliminarFactura = false">Cancelar</button>
                    </div>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           @change="onFile" class="input" />
                    <div v-if="errors.factura" class="error">{{ errors.factura[0] }}</div>
                </div>
            </div>
        </form>
        <template #footer>
            <button type="button" class="btn btn-secondary" @click="open = false">Cancelar</button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="submit">
                <span v-if="saving" class="loader" /> {{ saving ? 'Guardando…' : 'Guardar' }}
            </button>
        </template>
    </BaseModal>

    <ExpedienteFormModal ref="expedienteModalRef" @guardado="expedienteCreado" />
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { movimientosService } from '@/services/movimientos';
import { expedientesService } from '@/services/expedientes';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtMoney } from '@/composables/useFormat';
import BaseModal from './BaseModal.vue';
import SelectBuscador from './SelectBuscador.vue';
import ExpedienteFormModal from './ExpedienteFormModal.vue';

/**
 * Acciones que pueden originar un movimiento de la cuenta. Además de las
 * facturas hay transferencias hacia otra cuenta (de la misma o de otra
 * gerencia) y pagos de incentivos o MCH (Mayor Carga Horaria).
 */
const ACCIONES = [
    { value: 'factura',       label: 'Factura' },
    { value: 'transferencia', label: 'Transferencia a otra cuenta' },
    { value: 'incentivo',     label: 'Pago de incentivos' },
    { value: 'mch',           label: 'MCH (Mayor Carga Horaria)' },
];

const AYUDAS = {
    factura:       'Solicitud o recepción de factura: la contraparte es el cliente o el proveedor.',
    transferencia: 'Movimiento de fondos hacia o desde otra cuenta.',
    incentivo:     'Pago de incentivos imputado a un rubro.',
    mch:           'Pago de Mayor Carga Horaria imputado a un rubro.',
};

const props = defineProps({
    /** Cuenta en la que se registra el movimiento. */
    cuentaOperativaId: { type: [Number, String], default: null },
});
const emit = defineEmits(['saved']);

const open = ref(false);
const saving = ref(false);
const errors = ref({});
const archivo = ref(null);
const eliminarFactura = ref(false);
const editingId = ref(null);
const currentFactura = ref(null);
const expedientes = ref([]);
const cuentas = ref([]);

const VACIO = {
    tipo: 'ingreso',
    accion: 'factura',
    expediente_id: null,
    proveedor: '',
    cliente: '',
    cuenta_contraparte_id: null,
    rubro: '',
    moneda: 'Peso',
    monto: null,
    monto_dolares: null,
    cotizacion: null,
    objeto: '',
};

const data = reactive({ ...VACIO });

const isEdit = computed(() => !!editingId.value);
const ayudaAccion = computed(() => AYUDAS[data.accion] || '');
/** Los incentivos y la MCH son siempre gastos. */
const tipoFijo = computed(() => data.accion === 'incentivo' || data.accion === 'mch');
const admiteFactura = computed(() => data.accion === 'factura' && data.tipo === 'gasto');

/** Campo de contraparte que corresponde a la acción y el tipo elegidos. */
const contraparte = computed(() => {
    if (data.accion === 'transferencia') return 'cuenta';
    if (data.accion === 'incentivo' || data.accion === 'mch') return 'rubro';
    return data.tipo === 'ingreso' ? 'cliente' : 'proveedor';
});

/** Cuentas a las que se puede transferir: todas menos la propia. */
const cuentasDestino = computed(() =>
    cuentas.value.filter(c => String(c.id) !== String(props.cuentaOperativaId)));

const opcionesCuenta = computed(() => cuentasDestino.value.map(c => ({
    value: c.id,
    etiqueta: c.etiqueta,
    deshabilitada: !c.permitida,
})));

/** El expediente del movimiento se elige entre los cargados en el sistema. */
const opcionesExpediente = computed(() => expedientes.value.map(e => ({
    value: e.id,
    etiqueta: `${e.nro_expediente}${e.cuenta_operativa ? ` · ${e.cuenta_operativa.nombre}` : ''}`,
})));

const expedienteModalRef = ref(null);

function nuevoExpediente() {
    expedienteModalRef.value?.show();
}

/** Al darlo de alta queda en la lista y elegido, sin perder lo ya cargado. */
function expedienteCreado(expediente) {
    if (!expedientes.value.some(c => c.id === expediente.id)) {
        expedientes.value = [expediente, ...expedientes.value];
    }
    data.expediente_id = expediente.id;
}

const title = computed(() => {
    const accion = ACCIONES.find(a => a.value === data.accion)?.label || 'Movimiento';
    return `${isEdit.value ? 'Editar' : 'Nuevo'} — ${accion}`;
});

const montoPesosCalc = computed(() => {
    const usd = Number(data.monto_dolares) || 0;
    const cot = Number(data.cotizacion) || 0;
    return usd * cot;
});

const toast = useToast();

watch(() => data.accion, async (a) => {
    if (a === 'incentivo' || a === 'mch') data.tipo = 'gasto';
    if (a === 'transferencia' && !cuentas.value.length) {
        await cargarCuentas();
    }
});

// Al cambiar la contraparte se limpian los campos que dejaron de aplicar.
watch(contraparte, (actual) => {
    if (actual !== 'cliente')   data.cliente = '';
    if (actual !== 'proveedor') data.proveedor = '';
    if (actual !== 'rubro')     data.rubro = '';
    if (actual !== 'cuenta')    data.cuenta_contraparte_id = null;
});

watch(() => data.moneda, (m) => {
    if (m === 'Peso') {
        data.monto_dolares = null;
        data.cotizacion = null;
    } else {
        data.monto = null;
    }
});

async function cargarExpedientes() {
    try {
        const res = await expedientesService.list({ per_page: 500 });
        expedientes.value = res.data || [];
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los expedientes.'));
    }
}

/**
 * Cuentas del árbol, sangradas por nivel: el árbol se ve entero, pero sólo se
 * puede transferir a las cuentas que el servidor marca como permitidas.
 */
async function cargarCuentas() {
    try {
        const arbol = await expedientesService.arbolEstructura();
        const filas = [];
        const recorrer = (nodo, nivel) => {
            const sangria = '\u00A0\u00A0'.repeat(nivel);
            for (const c of nodo.cuentas || []) {
                filas.push({ ...c, etiqueta: `${sangria}${nodo.nombre} · ${c.nombre}` });
            }
            for (const h of nodo.hijos || []) recorrer(h, nivel + 1);
        };
        if (arbol) recorrer(arbol, 0);
        cuentas.value = filas;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar las cuentas.'));
    }
}

function reset(initial = {}) {
    Object.assign(data, VACIO, initial);
    archivo.value = null;
    eliminarFactura.value = false;
    errors.value = {};
}

async function show({ movimiento = null, accion = 'factura', tipo = 'ingreso' } = {}) {
    if (movimiento) {
        editingId.value = movimiento.id;
        currentFactura.value = movimiento.has_factura ? (movimiento.factura_original_name || 'factura adjunta') : null;
        reset({
            tipo: movimiento.tipo,
            accion: movimiento.accion || 'factura',
            expediente_id: movimiento.expediente_id ?? null,
            proveedor: movimiento.proveedor || '',
            cliente: movimiento.cliente || '',
            cuenta_contraparte_id: movimiento.cuenta_contraparte_id ?? null,
            rubro: movimiento.rubro || '',
            moneda: movimiento.moneda,
            monto: movimiento.monto != null ? Number(movimiento.monto) : null,
            monto_dolares: movimiento.monto_dolares != null ? Number(movimiento.monto_dolares) : null,
            cotizacion: movimiento.cotizacion != null ? Number(movimiento.cotizacion) : null,
            objeto: movimiento.objeto || '',
        });
    } else {
        editingId.value = null;
        currentFactura.value = null;
        reset({ accion, tipo: (accion === 'incentivo' || accion === 'mch') ? 'gasto' : tipo });
    }

    if (!expedientes.value.length) await cargarExpedientes();
    if (data.accion === 'transferencia' && !cuentas.value.length) await cargarCuentas();

    open.value = true;
}

defineExpose({ show });

function onFile(ev) {
    archivo.value = ev.target.files?.[0] || null;
}

async function descargarFactura() {
    if (!editingId.value) return;
    try {
        await movimientosService.downloadFactura(editingId.value, currentFactura.value);
    } catch (err) {
        toast.error(extractError(err, 'No se pudo descargar la factura.'));
    }
}

async function submit() {
    errors.value = {};
    saving.value = true;
    try {
        const payload = { ...data };
        if (archivo.value) payload.factura = archivo.value;
        if (eliminarFactura.value) payload.eliminar_factura = true;

        if (isEdit.value) {
            await movimientosService.update(editingId.value, payload);
            toast.success('Movimiento actualizado.');
        } else {
            await movimientosService.create(props.cuentaOperativaId, payload);
            toast.success('Movimiento creado.');
        }
        open.value = false;
        emit('saved');
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            errors.value = err.response.data.errors;
            toast.error('Hay errores de validación.');
        } else {
            toast.error(extractError(err, 'No se pudo guardar el movimiento.'));
        }
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.hint { font-size: 12px; color: var(--color-muted, #888); margin-top: 4px; }
</style>
