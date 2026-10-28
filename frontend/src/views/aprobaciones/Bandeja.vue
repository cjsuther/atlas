<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Para aprobar</h1>
                <p class="page-subtitle">
                    Lo que se cargó en las cuentas de su alcance. No mueve el saldo hasta que se aprueba.
                </p>
            </div>
            <button class="btn btn-secondary" @click="load" :disabled="loading">Actualizar</button>
        </div>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Buscar</label>
                    <input v-model="filtro" type="text" class="input"
                           placeholder="Cuenta, expediente, objeto, contraparte o quien lo cargó…" />
                </div>
                <div class="field">
                    <label>Pedido</label>
                    <SelectBuscador v-model="filtroPedido"
                                    :opciones="Object.entries(PEDIDOS).map(([value, p]) => ({ value, etiqueta: p.label }))"
                                    opcion-vacia="Todos" valor-vacio="" placeholder="Todos" />
                </div>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">No hay nada esperando aprobación.</div>
        <div v-else-if="!filas.length" class="empty-state">Nada coincide con el filtro.</div>

        <div v-else class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>Pedido por</th>
                        <th>Cuenta</th>
                        <th>Movimiento</th>
                        <th>Objeto</th>
                        <th style="text-align:right;">Monto</th>
                        <th>Factura</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in filas" :key="m.id">
                        <td style="white-space:nowrap;">
                            <span :class="['badge', PEDIDOS[m.pedido].badge]">{{ PEDIDOS[m.pedido].label }}</span>
                            <div v-if="m.reenvio" style="font-size:11px;color:var(--color-muted);margin-top:2px;"
                                 :title="m.aprobacion_observacion">
                                Corregido después de un rechazo
                            </div>
                        </td>
                        <td>
                            <div>{{ m.solicitado_por_nombre || m.solicitado_por || '—' }}</div>
                            <div style="font-size:11px;color:var(--color-muted);">{{ fmtDateTime(m.solicitado_fecha) }}</div>
                        </td>
                        <td>
                            <router-link v-if="m.cuenta" :to="{ name: 'cuenta-detalle', params: { id: m.cuenta.id } }">
                                {{ m.cuenta.nombre }}
                            </router-link>
                            <div v-if="m.cuenta_ruta" style="font-size:11px;color:var(--color-muted);">{{ m.cuenta_ruta }}</div>
                        </td>
                        <td>
                            <span :class="['badge', m.tipo === 'ingreso' ? 'badge-success' : 'badge-warning']">{{ m.tipo }}</span>
                            {{ ACCION_LABELS[m.accion] || m.accion }}
                            <div style="font-size:11px;color:var(--color-muted);">
                                #{{ m.id }} · {{ fechaComprobante(m.fecha) }}
                                <template v-if="m.expediente">
                                    · <router-link :to="{ name: 'expedientes-detalle', params: { id: m.expediente.id } }">
                                        {{ m.expediente.nro_expediente }}
                                    </router-link>
                                </template>
                            </div>
                            <div v-if="m.contraparte" style="font-size:11px;color:var(--color-muted);">
                                {{ m.contraparte }}
                            </div>
                        </td>
                        <td style="max-width:300px;">
                            <div style="white-space:normal;">{{ m.objeto }}</div>
                            <div v-if="m.distribuciones?.length" style="font-size:11px;color:var(--color-muted);">
                                Repartido:
                                {{ m.distribuciones.map(p => `${fmtPct(p.distribucion_porcentaje)} a ${p.cuenta_contraparte?.nombre || 'otra cuenta'}`).join(' · ') }}
                            </div>
                            <!-- Qué cambia el pedido de modificación. -->
                            <table v-if="m.cambios?.length" class="cambios">
                                <tr v-for="c in m.cambios" :key="c.campo">
                                    <td>{{ c.campo }}</td>
                                    <td class="antes">{{ c.antes ?? '—' }}</td>
                                    <td>→</td>
                                    <td class="despues">{{ c.despues ?? '—' }}</td>
                                </tr>
                            </table>
                            <div v-else-if="m.pedido === 'modificacion'" style="font-size:11px;color:var(--color-muted);">
                                Sin cambios en los datos.
                            </div>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <div style="font-weight:600;">${{ fmtMoney(m.monto) }}</div>
                            <div v-if="m.moneda === 'Dólar'" style="font-size:11px;color:var(--color-muted);">
                                USD {{ fmtMoney(m.monto_dolares) }}
                            </div>
                        </td>
                        <td>
                            <button v-if="m.has_factura" class="btn btn-ghost btn-sm" @click="descargar(m)"
                                    :title="m.factura_original_name">📎 Ver</button>
                            <span v-else style="color:var(--color-muted);font-size:12px;">—</span>
                        </td>
                        <td style="white-space:nowrap;">
                            <template v-if="m.puede_decidir">
                                <button class="btn btn-primary btn-sm" @click="abrir(m, 'aprobar')">Aprobar</button>
                                <button class="btn btn-secondary btn-sm" style="margin-left:6px;"
                                        @click="abrir(m, 'rechazar')">Rechazar</button>
                            </template>
                            <span v-else style="font-size:12px;color:var(--color-muted);">{{ m.motivo_no_decide }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <BaseModal v-model="decisionOpen" :title="tituloDecision">
            <div v-if="actual">
                <p style="margin-top:0;">
                    <strong>{{ PEDIDOS[actual.pedido].label }}</strong> ·
                    {{ actual.cuenta?.nombre }} ·
                    {{ actual.tipo }} {{ ACCION_LABELS[actual.accion] || actual.accion }} ·
                    ${{ fmtMoney(actual.monto) }}
                </p>
                <p style="color:var(--color-muted);font-size:13px;">{{ efectoDecision }}</p>
                <div class="field">
                    <label>
                        Observación
                        <span v-if="accion === 'rechazar'" style="color:var(--color-danger);">*</span>
                    </label>
                    <textarea v-model="observacion" class="textarea" rows="4" maxlength="2000"
                              :placeholder="accion === 'rechazar'
                                  ? 'Por qué se rechaza: lo va a leer quien lo cargó.'
                                  : 'Opcional.'" />
                    <div v-if="errorObs" class="error">{{ errorObs }}</div>
                </div>
            </div>
            <template #footer>
                <button class="btn btn-secondary" @click="decisionOpen = false" :disabled="decidiendo">Cancelar</button>
                <button :class="['btn', accion === 'rechazar' ? 'btn-danger' : 'btn-primary']"
                        @click="decidir" :disabled="decidiendo">
                    <span v-if="decidiendo" class="loader" />
                    {{ accion === 'rechazar' ? 'Rechazar' : 'Aprobar' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { aprobacionesService } from '@/services/aprobaciones';
import { movimientosService } from '@/services/movimientos';
import { extractError } from '@/services/http';
import { useToast } from '@/composables/useToast';
import { usePendientes } from '@/composables/usePendientes';
import { fmtMoney, fmtDateTime } from '@/composables/useFormat';
import BaseModal from '@/components/BaseModal.vue';
import SelectBuscador from '@/components/SelectBuscador.vue';

const toast = useToast();
const { refrescar: refrescarPendientes } = usePendientes();

const PEDIDOS = {
    alta:         { label: 'Alta',         badge: 'badge-info' },
    modificacion: { label: 'Modificación', badge: 'badge-warning' },
    baja:         { label: 'Baja',         badge: 'badge-danger' },
};

const ACCION_LABELS = {
    factura:       'por factura',
    transferencia: 'transferencia',
    incentivo:     'incentivo',
    mch:           'MCH',
};

const fmtPct = (v) => `${String(Number(v)).replace('.', ',')} %`;

function fechaComprobante(f) {
    if (!f) return '—';
    const [a, mes, d] = String(f).slice(0, 10).split('-');
    return `${d}/${mes}/${a}`;
}

const rows = ref([]);
const loading = ref(false);
const filtro = ref('');
const filtroPedido = ref('');

const filas = computed(() => {
    const t = filtro.value.trim().toLowerCase();
    return rows.value.filter(m => {
        if (filtroPedido.value && m.pedido !== filtroPedido.value) return false;
        if (!t) return true;
        return [m.cuenta?.nombre, m.cuenta_ruta, m.expediente?.nro_expediente, m.objeto,
                m.contraparte, m.solicitado_por, m.solicitado_por_nombre]
            .some(v => String(v ?? '').toLowerCase().includes(t));
    });
});

async function load() {
    loading.value = true;
    try {
        rows.value = (await aprobacionesService.bandeja()).data || [];
    } catch (err) {
        toast.error(extractError(err, 'No se pudo cargar lo que espera aprobación.'));
    } finally {
        loading.value = false;
    }
}

async function descargar(m) {
    try {
        await movimientosService.downloadFactura(m.id, m.factura_original_name);
    } catch (err) {
        toast.error(extractError(err, 'No se pudo descargar la factura.'));
    }
}

// ------------------------------------------------------------------
// Decisión
// ------------------------------------------------------------------

const decisionOpen = ref(false);
const actual = ref(null);
const accion = ref('aprobar');
const observacion = ref('');
const errorObs = ref('');
const decidiendo = ref(false);

const tituloDecision = computed(() => accion.value === 'rechazar' ? 'Rechazar' : 'Aprobar');

/** Lo que pasa con el saldo según lo que se decida. */
const efectoDecision = computed(() => {
    const m = actual.value;
    if (!m) return '';
    const aprobar = accion.value === 'aprobar';
    if (m.pedido === 'alta') {
        return aprobar
            ? 'Al aprobarlo empieza a sumar en el saldo de la cuenta.'
            : 'No va a mover el saldo: vuelve a quien lo cargó para que lo corrija.';
    }
    if (m.pedido === 'baja') {
        return aprobar
            ? 'Al aprobar la baja, el movimiento deja de sumar en el saldo.'
            : 'El movimiento sigue como estaba y sigue sumando en el saldo.';
    }
    return aprobar
        ? 'Al aprobar la modificación, el saldo toma los valores nuevos.'
        : 'El movimiento sigue con los valores aprobados.';
});

function abrir(m, que) {
    actual.value = m;
    accion.value = que;
    observacion.value = '';
    errorObs.value = '';
    decisionOpen.value = true;
}

async function decidir() {
    errorObs.value = '';
    if (accion.value === 'rechazar' && !observacion.value.trim()) {
        errorObs.value = 'Escriba por qué lo rechaza: es lo que va a leer quien lo cargó.';
        return;
    }
    decidiendo.value = true;
    try {
        const r = accion.value === 'rechazar'
            ? await aprobacionesService.rechazar(actual.value.id, observacion.value)
            : await aprobacionesService.aprobar(actual.value.id, observacion.value);
        toast.success(r.message || 'Listo.');
        decisionOpen.value = false;
        await load();
        refrescarPendientes();
    } catch (err) {
        const obs = err?.response?.data?.errors?.observacion;
        if (obs) errorObs.value = obs[0];
        else toast.error(extractError(err, 'No se pudo registrar la decisión.'));
    } finally {
        decidiendo.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.cambios {
    margin-top: 6px;
    font-size: 12px;
    border-collapse: collapse;
}
.cambios td {
    padding: 1px 6px 1px 0;
    vertical-align: top;
    white-space: normal;
}
.cambios td:first-child { color: var(--color-muted); }
.cambios .antes { text-decoration: line-through; color: var(--color-muted); }
.cambios .despues { font-weight: 600; }
</style>
