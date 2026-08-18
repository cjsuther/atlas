<template>
    <details class="collapsible" open>
        <summary>
            Ejecución de gastos e ingresos
            <span style="font-weight:400;opacity:0.9;font-size:13px;">
                ({{ totalGastos > 0 || totalIngresos > 0
                    ? `Ingresos $${fmtMoney(totalIngresos)} · Gastos $${fmtMoney(totalGastos)}`
                    : 'sin movimientos' }})
            </span>
        </summary>
        <div class="collapsible-body">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" :class="['btn', 'btn-sm', filter === '' ? 'btn-primary' : 'btn-secondary']"
                            @click="setFilter('')">Todos</button>
                    <button type="button" :class="['btn', 'btn-sm', filter === 'ingreso' ? 'btn-primary' : 'btn-secondary']"
                            @click="setFilter('ingreso')">Ingresos</button>
                    <button type="button" :class="['btn', 'btn-sm', filter === 'gasto' ? 'btn-primary' : 'btn-secondary']"
                            @click="setFilter('gasto')">Gastos</button>
                    <select v-model="filterAccion" class="select" style="height:30px;padding:2px 8px;font-size:12px;"
                            @change="load">
                        <option value="">Todas las acciones</option>
                        <option v-for="(label, value) in ACCION_LABELS" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>
                <div v-if="auth.canEdit" style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="btn btn-primary btn-sm"   @click="nuevo('factura', 'ingreso')">+ Ingreso por factura</button>
                    <button class="btn btn-secondary btn-sm" @click="nuevo('factura', 'gasto')">+ Gasto por factura</button>
                    <button class="btn btn-secondary btn-sm" @click="nuevo('transferencia', 'gasto')">+ Transferencia</button>
                    <button class="btn btn-secondary btn-sm" @click="nuevo('incentivo', 'gasto')">+ Incentivo</button>
                    <button class="btn btn-secondary btn-sm" @click="nuevo('mch', 'gasto')">+ MCH</button>
                </div>
            </div>

            <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
            <div v-else-if="!rows.length" class="empty-state">No hay movimientos cargados.</div>

            <div v-else class="table-wrapper">
                <table class="atlas-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Acción</th>
                            <th>Expediente</th>
                            <th>Contraparte</th>
                            <th>Objeto</th>
                            <th style="text-align:right;">Monto</th>
                            <th>Factura</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in rows" :key="m.id">
                            <td>
                                <span :class="['badge', m.tipo === 'ingreso' ? 'badge-success' : 'badge-warning']">
                                    {{ m.tipo }}
                                </span>
                            </td>
                            <td>{{ ACCION_LABELS[m.accion] || m.accion }}</td>
                            <td>{{ m.nro_expediente }}</td>
                            <td>
                                <div>{{ m.contraparte || '—' }}</div>
                                <div v-if="m.contraparte_tipo" style="font-size:11px;color:var(--color-muted);">
                                    {{ CONTRAPARTE_LABELS[m.contraparte_tipo] }}
                                </div>
                            </td>
                            <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                :title="m.objeto">{{ m.objeto }}</td>
                            <td style="text-align:right;">
                                <div style="font-weight:600;">${{ fmtMoney(m.monto) }}</div>
                                <div v-if="m.moneda === 'Dólar'" style="font-size:11px;color:var(--color-muted);">
                                    USD {{ fmtMoney(m.monto_dolares) }} · cot. {{ m.cotizacion }}
                                </div>
                            </td>
                            <td>
                                <button v-if="m.has_factura" class="btn btn-ghost btn-sm" @click="descargar(m)"
                                        :title="m.factura_original_name">📎 Descargar</button>
                                <span v-else style="color:var(--color-muted);font-size:12px;">—</span>
                            </td>
                            <td class="actions">
                                <button v-if="auth.canEdit" @click="editar(m)" title="Editar">
                                    <IconLib name="edit" :size="14" />
                                </button>
                                <button v-if="auth.canEdit" class="danger" @click="darBaja(m)" title="Eliminar">
                                    <IconLib name="trash" :size="14" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <MovimientoFormModal ref="modalRef" :contrato-ejecucion-id="contratoEjecucionId" @saved="onSaved" />
    <ConfirmDialog ref="confirmRef" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { movimientosService } from '@/services/movimientos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtMoney } from '@/composables/useFormat';
import IconLib from './IconLib.vue';
import MovimientoFormModal from './MovimientoFormModal.vue';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps({
    contratoEjecucionId: { type: [Number, String], required: true },
});
const emit = defineEmits(['changed']);

const auth = useAuthStore();
const toast = useToast();

const ACCION_LABELS = {
    factura:       'Factura',
    transferencia: 'Transferencia',
    incentivo:     'Incentivo',
    mch:           'MCH',
};

const CONTRAPARTE_LABELS = {
    cliente:   'Cliente',
    proveedor: 'Proveedor',
    contrato:  'Contrato',
    rubro:     'Rubro',
};

const rows = ref([]);
const loading = ref(false);
const filter = ref('');
const filterAccion = ref('');
const modalRef = ref(null);
const confirmRef = ref(null);

const totalIngresos = computed(() =>
    rows.value.filter(r => r.tipo === 'ingreso').reduce((s, r) => s + Number(r.monto || 0), 0));
const totalGastos = computed(() =>
    rows.value.filter(r => r.tipo === 'gasto').reduce((s, r) => s + Number(r.monto || 0), 0));

async function load() {
    loading.value = true;
    try {
        const params = { per_page: 200 };
        if (filter.value) params.tipo = filter.value;
        if (filterAccion.value) params.accion = filterAccion.value;
        const res = await movimientosService.listForContrato(props.contratoEjecucionId, params);
        rows.value = res.data || [];
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los movimientos.'));
    } finally {
        loading.value = false;
    }
}

function setFilter(v) {
    filter.value = v;
    load();
}

function nuevo(accion, tipo) {
    modalRef.value.show({ accion, tipo });
}
function editar(m) {
    modalRef.value.show({ movimiento: m });
}
function onSaved() {
    load();
    emit('changed');
}
async function descargar(m) {
    try {
        await movimientosService.downloadFactura(m.id, m.factura_original_name);
    } catch (err) {
        toast.error(extractError(err, 'No se pudo descargar la factura.'));
    }
}
async function darBaja(m) {
    const ok = await confirmRef.value.show({
        title: 'Dar de baja',
        message: m.accion === 'transferencia'
            ? '¿Eliminar esta transferencia? También se dará de baja la contrapartida en el otro contrato.'
            : `¿Eliminar este ${m.tipo}? Queda registrado en el historial.`,
        confirmText: 'Eliminar',
        danger: true,
    });
    if (!ok) return;
    try {
        await movimientosService.remove(m.id);
        toast.success('Movimiento eliminado.');
        load();
        emit('changed');
    } catch (err) {
        toast.error(extractError(err, 'No se pudo eliminar.'));
    }
}

onMounted(load);
</script>
