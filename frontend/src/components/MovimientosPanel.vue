<template>
    <details class="collapsible" open>
        <summary>
            {{ soloConsulta ? 'Movimientos relacionados' : 'Movimientos de la cuenta' }}
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
                    <SelectBuscador v-model="filterAccion" style="min-width:190px;"
                                    :opciones="Object.entries(ACCION_LABELS).map(([value, etiqueta]) => ({ value, etiqueta }))"
                                    opcion-vacia="Todas las acciones" valor-vacio=""
                                    placeholder="Todas las acciones"
                                    @update:model-value="load" />
                </div>
                <div v-if="auth.canEdit && !soloConsulta" style="display:flex;gap:6px;flex-wrap:wrap;">
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
                            <ThOrden campo="tipo" :orden="orden" @ordenar="ordenarPor">Tipo</ThOrden>
                            <ThOrden campo="accion" :orden="orden" @ordenar="ordenarPor">Acción</ThOrden>
                            <ThOrden campo="relacion" :orden="orden" @ordenar="ordenarPor">
                                {{ soloConsulta ? 'Cuenta' : 'Expediente' }}
                            </ThOrden>
                            <ThOrden campo="contraparte" :orden="orden" @ordenar="ordenarPor">Contraparte</ThOrden>
                            <ThOrden campo="objeto" :orden="orden" @ordenar="ordenarPor">Objeto</ThOrden>
                            <ThOrden campo="monto" :orden="orden" derecha @ordenar="ordenarPor">Monto</ThOrden>
                            <ThOrden campo="has_factura" :orden="orden" @ordenar="ordenarPor">Factura</ThOrden>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in filasOrdenadas" :key="m.id">
                            <td>
                                <span :class="['badge', m.tipo === 'ingreso' ? 'badge-success' : 'badge-warning']">
                                    {{ m.tipo }}
                                </span>
                            </td>
                            <td>{{ ACCION_LABELS[m.accion] || m.accion }}</td>
                            <td>
                                <router-link v-if="soloConsulta && m.cuenta"
                                             :to="{ name: 'cuenta-detalle', params: { id: m.cuenta.id } }">
                                    {{ m.cuenta.nombre }}
                                </router-link>
                                <router-link v-else-if="!soloConsulta && m.expediente"
                                             :to="{ name: 'expedientes-detalle', params: { id: m.expediente.id } }">
                                    {{ m.expediente.nro_expediente }}
                                </router-link>
                                <span v-else style="color:var(--color-muted);">—</span>
                            </td>
                            <td>
                                <div>{{ m.contraparte || '—' }}</div>
                                <div v-if="m.contraparte_tipo" style="font-size:11px;color:var(--color-muted);">
                                    {{ CONTRAPARTE_LABELS[m.contraparte_tipo] }}
                                </div>
                            </td>
                            <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                :title="m.objeto">
                                <a v-if="auth.canEdit && !soloConsulta" href="#" @click.prevent="editar(m)">
                                    {{ m.objeto || 'Sin objeto' }}
                                </a>
                                <template v-else>{{ m.objeto }}</template>
                            </td>
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
                                <button v-if="auth.canEdit && !soloConsulta" @click="editar(m)" title="Editar">
                                    <IconLib name="edit" :size="14" />
                                </button>
                                <button v-if="auth.canEdit && !soloConsulta" class="danger" @click="darBaja(m)" title="Eliminar">
                                    <IconLib name="trash" :size="14" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <MovimientoFormModal v-if="!soloConsulta" ref="modalRef"
                         :cuenta-operativa-id="cuentaOperativaId" @saved="onSaved" />
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
import SelectBuscador from './SelectBuscador.vue';
import ThOrden from './ThOrden.vue';
import { useOrdenTabla } from '@/composables/useOrden';
import MovimientoFormModal from './MovimientoFormModal.vue';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Lista de movimientos. Con `cuentaOperativaId` es el historial de la cuenta y
 * desde ahí se registran los movimientos; con `expedienteId` es sólo
 * consulta: lo que se registró contra ese expediente.
 */
const props = defineProps({
    cuentaOperativaId:   { type: [Number, String], default: null },
    expedienteId: { type: [Number, String], default: null },
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
    cuenta:    'Cuenta',
    rubro:     'Rubro',
};

/** Desde el expediente los movimientos se ven, no se cargan. */
const soloConsulta = computed(() => !props.cuentaOperativaId);

const rows = ref([]);
const loading = ref(false);

// Las filas ya están todas cargadas, así que el orden se resuelve acá.
const { orden, ordenarPor, filasOrdenadas } = useOrdenTabla(rows, {
    accesores: {
        monto:    (m) => Number(m.monto || 0),
        relacion: (m) => (m.cuenta?.nombre ?? m.expediente?.nro_expediente ?? ''),
    },
});
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
        const res = soloConsulta.value
            ? await movimientosService.listForExpediente(props.expedienteId, params)
            : await movimientosService.listForCuenta(props.cuentaOperativaId, params);
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
            ? '¿Eliminar esta transferencia? También se dará de baja la contrapartida en la otra cuenta.'
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
