<template>
    <BaseModal v-model="open" :title="title" size="large">
        <form @submit.prevent="submit">
            <div class="form-grid">
                <!-- Acción: qué originó el movimiento -->
                <div class="field">
                    <label>Acción *</label>
                    <select v-model="data.accion" class="select" :disabled="isEdit" required>
                        <option v-for="a in ACCIONES" :key="a.value" :value="a.value">{{ a.label }}</option>
                    </select>
                    <div class="hint">{{ ayudaAccion }}</div>
                    <div v-if="errors.accion" class="error">{{ errors.accion[0] }}</div>
                </div>

                <!-- Tipo: signo del movimiento -->
                <div class="field">
                    <label>Tipo *</label>
                    <select v-model="data.tipo" class="select" :disabled="isEdit || tipoFijo" required>
                        <option value="ingreso">Ingreso</option>
                        <option value="gasto">Gasto</option>
                    </select>
                    <div v-if="tipoFijo" class="hint">Los incentivos y la MCH se registran siempre como gasto.</div>
                    <div v-if="errors.tipo" class="error">{{ errors.tipo[0] }}</div>
                </div>

                <!-- Expediente (mismo componente que en contratos) -->
                <div class="field">
                    <label>Expediente *</label>
                    <ExpedienteInput v-model="data.nro_expediente" />
                    <div v-if="errors.nro_expediente" class="error">{{ errors.nro_expediente[0] }}</div>
                </div>

                <!-- Contraparte: depende de la acción. No siempre hay cliente o
                     proveedor; a veces es otro contrato y a veces sólo un rubro. -->
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
                <div v-else-if="contraparte === 'contrato'" class="field">
                    <label>Contrato contraparte *</label>
                    <select v-model="data.contrato_contraparte_id" class="select" required>
                        <option :value="null">—</option>
                        <option v-for="c in contratosDestino" :key="c.id" :value="c.id">
                            #{{ c.id }} · {{ c.nro_expediente }} — {{ c.nombre_proyecto }}
                        </option>
                    </select>
                    <div class="hint">
                        Se registra automáticamente la contrapartida en el otro contrato.
                    </div>
                    <div v-if="errors.contrato_contraparte_id" class="error">{{ errors.contrato_contraparte_id[0] }}</div>
                </div>
                <div v-else class="field">
                    <label>Rubro *</label>
                    <input v-model="data.rubro" class="input" required maxlength="200" />
                    <div v-if="errors.rubro" class="error">{{ errors.rubro[0] }}</div>
                </div>

                <!-- Moneda -->
                <div class="field">
                    <label>Moneda *</label>
                    <select v-model="data.moneda" class="select" required>
                        <option value="Peso">Peso (ARS)</option>
                        <option value="Dólar">Dólar (USD)</option>
                    </select>
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

                <!-- Factura (sólo ingresos por factura) -->
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
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { movimientosService } from '@/services/movimientos';
import { contratosEjecucionService } from '@/services/contratosEjecucion';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtMoney } from '@/composables/useFormat';
import BaseModal from './BaseModal.vue';
import ExpedienteInput from './ExpedienteInput.vue';

/**
 * Acciones que pueden originar un movimiento de ejecución. Además de las
 * facturas hay transferencias hacia otro contrato (de la misma o de otra
 * gerencia) y pagos de incentivos o MCH (Mayor Carga Horaria).
 */
const ACCIONES = [
    { value: 'factura',       label: 'Factura' },
    { value: 'transferencia', label: 'Transferencia a otro contrato' },
    { value: 'incentivo',     label: 'Pago de incentivos' },
    { value: 'mch',           label: 'MCH (Mayor Carga Horaria)' },
];

const AYUDAS = {
    factura:       'Solicitud o recepción de factura: la contraparte es el cliente o el proveedor.',
    transferencia: 'Movimiento de fondos hacia o desde otro contrato.',
    incentivo:     'Pago de incentivos imputado a un rubro.',
    mch:           'Pago de Mayor Carga Horaria imputado a un rubro.',
};

const props = defineProps({
    contratoEjecucionId: { type: [Number, String], default: null },
});
const emit = defineEmits(['saved']);

const open = ref(false);
const saving = ref(false);
const errors = ref({});
const archivo = ref(null);
const eliminarFactura = ref(false);
const editingId = ref(null);
const currentFactura = ref(null);
const contratos = ref([]);

const VACIO = {
    tipo: 'ingreso',
    accion: 'factura',
    nro_expediente: '',
    proveedor: '',
    cliente: '',
    contrato_contraparte_id: null,
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
const admiteFactura = computed(() => data.accion === 'factura' && data.tipo === 'ingreso');

/** Campo de contraparte que corresponde a la acción y el tipo elegidos. */
const contraparte = computed(() => {
    if (data.accion === 'transferencia') return 'contrato';
    if (data.accion === 'incentivo' || data.accion === 'mch') return 'rubro';
    return data.tipo === 'ingreso' ? 'cliente' : 'proveedor';
});

const contratosDestino = computed(() =>
    contratos.value.filter(c => String(c.id) !== String(props.contratoEjecucionId)));

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
    if (a === 'transferencia' && !contratos.value.length) {
        await cargarContratos();
    }
});

// Al cambiar la contraparte se limpian los campos que dejaron de aplicar.
watch(contraparte, (actual) => {
    if (actual !== 'cliente')   data.cliente = '';
    if (actual !== 'proveedor') data.proveedor = '';
    if (actual !== 'rubro')     data.rubro = '';
    if (actual !== 'contrato')  data.contrato_contraparte_id = null;
});

watch(() => data.moneda, (m) => {
    if (m === 'Peso') {
        data.monto_dolares = null;
        data.cotizacion = null;
    } else {
        data.monto = null;
    }
});

async function cargarContratos() {
    try {
        const res = await contratosEjecucionService.list({ per_page: 200 });
        contratos.value = res.data || [];
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los contratos.'));
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
            nro_expediente: movimiento.nro_expediente,
            proveedor: movimiento.proveedor || '',
            cliente: movimiento.cliente || '',
            contrato_contraparte_id: movimiento.contrato_contraparte_id ?? null,
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

    if (data.accion === 'transferencia' && !contratos.value.length) {
        await cargarContratos();
    }

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
            await movimientosService.create(props.contratoEjecucionId, payload);
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
