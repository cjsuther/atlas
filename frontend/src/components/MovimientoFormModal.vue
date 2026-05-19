<template>
    <BaseModal v-model="open" :title="title" size="large">
        <form @submit.prevent="submit">
            <div class="form-grid">
                <!-- Tipo -->
                <div class="field">
                    <label>Tipo *</label>
                    <select v-model="data.tipo" class="select" :disabled="isEdit" required>
                        <option value="ingreso">Ingreso</option>
                        <option value="gasto">Gasto</option>
                    </select>
                </div>

                <!-- Expediente (mismo componente que en contratos) -->
                <div class="field">
                    <label>Expediente *</label>
                    <ExpedienteInput v-model="data.nro_expediente" />
                    <div v-if="errors.nro_expediente" class="error">{{ errors.nro_expediente[0] }}</div>
                </div>

                <!-- Cliente o Proveedor según tipo -->
                <div v-if="data.tipo === 'gasto'" class="field">
                    <label>Proveedor *</label>
                    <input v-model="data.proveedor" class="input" required maxlength="300" />
                    <div v-if="errors.proveedor" class="error">{{ errors.proveedor[0] }}</div>
                </div>
                <div v-else class="field">
                    <label>Cliente *</label>
                    <input v-model="data.cliente" class="input" required maxlength="300" />
                    <div v-if="errors.cliente" class="error">{{ errors.cliente[0] }}</div>
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
                    <div class="field"><!-- spacer --></div>
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

                <!-- Factura (solo ingresos) -->
                <div v-if="data.tipo === 'ingreso'" class="field full">
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
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtMoney } from '@/composables/useFormat';
import BaseModal from './BaseModal.vue';
import ExpedienteInput from './ExpedienteInput.vue';

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

const data = reactive({
    tipo: 'ingreso',
    nro_expediente: '',
    proveedor: '',
    cliente: '',
    moneda: 'Peso',
    monto: null,
    monto_dolares: null,
    cotizacion: null,
    objeto: '',
});

const isEdit = computed(() => !!editingId.value);
const title = computed(() => isEdit.value
    ? `Editar ${data.tipo === 'gasto' ? 'gasto' : 'ingreso'}`
    : `Nuevo ${data.tipo === 'gasto' ? 'gasto' : 'ingreso'}`);

const montoPesosCalc = computed(() => {
    const usd = Number(data.monto_dolares) || 0;
    const cot = Number(data.cotizacion) || 0;
    return usd * cot;
});

const toast = useToast();

watch(() => data.tipo, (t) => {
    // Al cambiar tipo, limpiar el campo que no aplica
    if (t === 'gasto') data.cliente = '';
    if (t === 'ingreso') data.proveedor = '';
});
watch(() => data.moneda, (m) => {
    if (m === 'Peso') {
        data.monto_dolares = null;
        data.cotizacion = null;
    } else {
        data.monto = null;
    }
});

function reset(initial = {}) {
    Object.assign(data, {
        tipo: 'ingreso',
        nro_expediente: '',
        proveedor: '',
        cliente: '',
        moneda: 'Peso',
        monto: null,
        monto_dolares: null,
        cotizacion: null,
        objeto: '',
    }, initial);
    archivo.value = null;
    eliminarFactura.value = false;
    errors.value = {};
}

function show({ movimiento = null, tipo = 'ingreso' } = {}) {
    if (movimiento) {
        editingId.value = movimiento.id;
        currentFactura.value = movimiento.has_factura ? (movimiento.factura_original_name || 'factura adjunta') : null;
        reset({
            tipo: movimiento.tipo,
            nro_expediente: movimiento.nro_expediente,
            proveedor: movimiento.proveedor || '',
            cliente: movimiento.cliente || '',
            moneda: movimiento.moneda,
            monto: movimiento.monto != null ? Number(movimiento.monto) : null,
            monto_dolares: movimiento.monto_dolares != null ? Number(movimiento.monto_dolares) : null,
            cotizacion: movimiento.cotizacion != null ? Number(movimiento.cotizacion) : null,
            objeto: movimiento.objeto || '',
        });
    } else {
        editingId.value = null;
        currentFactura.value = null;
        reset({ tipo });
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
