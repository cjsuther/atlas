<template>
    <div>
        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!c" class="empty-state">Contrato no encontrado.</div>

        <div v-else>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1 class="page-title">
                        <span :class="['badge', badgeForEstado(c.estado)]">{{ c.estado?.nombre }}</span>
                        {{ c.nombre_proyecto }}
                    </h1>
                    <p class="page-subtitle">
                        #{{ c.id }} · {{ c.nro_expediente }} · {{ c.tipo_contrato?.sigla }}
                        <span v-if="c.sector">
                            · {{ c.sector.nombre }}
                            <template v-if="c.gerencia_area"> ({{ c.gerencia_area.nombre }})</template>
                        </span>
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <router-link v-if="auth.canEdit && !c.deleted_at"
                                 :to="{ name: 'contratos-ejecucion-editar', params: { id: c.id } }"
                                 class="btn btn-primary">
                        <IconLib name="edit" /> Editar
                    </router-link>
                    <button v-if="auth.isAdminSistema && !c.deleted_at" class="btn btn-secondary"
                            @click="abrirTransferencia">
                        Transferir a otro sector
                    </button>
                    <router-link :to="{ name: 'contratos-ejecucion' }" class="btn btn-secondary">Volver</router-link>
                </div>
            </div>

            <div class="tabs">
                <button type="button" :class="['tab', { activa: tab === 'detalle' }]"
                        @click="tab = 'detalle'">
                    Detalle
                </button>
                <button type="button" :class="['tab', { activa: tab === 'ejecucion' }]"
                        @click="tab = 'ejecucion'">
                    Ejecución e historial
                </button>
            </div>

            <div v-show="tab === 'detalle'" class="card">
                <div class="detail-section">
                    <h4>Identificación</h4>
                    <div class="detail-grid">
                        <Field label="Expediente" :value="c.nro_expediente" />
                        <Field label="F. Apertura" :value="fmtDate(c.fecha_apertura_expediente)" />
                        <Field label="Tipo" :value="c.tipo_contrato?.sigla + ' — ' + c.tipo_contrato?.nombre" />
                        <Field label="Estado" :value="c.estado?.nombre" />
                        <Field label="UTT" :value="c.utt ? `${c.utt.denominacion} — ${c.utt.nombre}` : '—'" />
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Áreas y responsables</h4>
                    <div class="detail-grid">
                        <Field label="Gerencia de Área" :value="c.gerencia_area?.nombre" />
                        <Field label="Sector" :value="c.sector?.nombre" />
                        <Field label="Solicitante" :value="c.solicitante?.razon_social" />
                        <Field label="UVT" :value="c.uvt ? `${c.uvt.siglas} — ${c.uvt.nombre}` : '—'" />
                        <Field label="Resp. 1" :value="responsable(c.resp1)" />
                        <Field label="Resp. 2" :value="responsable(c.resp2)" />
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Plazos y montos</h4>
                    <div class="detail-grid">
                        <Field label="Cliente / Contraparte" :value="c.cliente" />
                        <Field label="F. Inicio" :value="fmtDate(c.fecha_inicio)" />
                        <Field label="F. Vencimiento" :value="fmtDate(c.fecha_vencimiento)" />
                        <Field label="F. Finalización" :value="fmtDate(c.fecha_finalizacion)" />
                        <Field label="Duración (meses)" :value="c.duracion_meses" />
                        <Field label="Atraso (meses)" :value="c.atraso_meses" />
                        <Field label="Acta de finalización" :value="c.acta_finalizacion" />
                        <Field label="Prórroga" :value="c.prorroga ? 'Sí' : 'No'" />
                        <Field label="Renovación automática" :value="c.renovacion_automatica ? 'Sí' : 'No'" />
                        <Field label="Caja BAS" :value="c.caja_bas" />
                        <Field label="Moneda" :value="c.moneda" />
                        <Field label="Cotización" :value="c.cotizacion" />
                        <Field label="Saldo inicial" :value="fmtMoney(c.saldo_inicial)" />
                        <Field label="Ejecutado · Ingresos (suma mov.)" :value="fmtMoney(c.monto_ejecutado_ingresos)" />
                        <Field label="Ejecutado · Gastos (suma mov.)"   :value="fmtMoney(c.monto_ejecutado_gastos)" />
                        <Field label="Saldo (inicial + ing. − gtos.)"   :value="fmtMoney(c.saldo)" />
                    </div>
                </div>

                <div v-if="c.descripcion_objeto" class="detail-section">
                    <h4>Descripción</h4>
                    <p style="white-space:pre-wrap;margin:0;">{{ c.descripcion_objeto }}</p>
                </div>
                <div v-if="c.observaciones" class="detail-section">
                    <h4>Observaciones</h4>
                    <p style="white-space:pre-wrap;margin:0;">{{ c.observaciones }}</p>
                </div>
            </div>

            <div v-show="tab === 'ejecucion'">
                <MovimientosPanel :contrato-ejecucion-id="c.id" @changed="refrescar" />
                <HistorialPanel tabla="contratos_ejecucion" :id="c.id" />
            </div>

            <!-- Transferencia completa del contrato a otra gerencia -->
            <BaseModal v-model="transferOpen" title="Transferir contrato a otro sector">
                <form @submit.prevent="transferir">
                    <p style="margin-top:0;font-size:13px;color:var(--color-muted);">
                        El contrato y todos sus movimientos de ejecución pasan al sector de destino,
                        que puede estar en otra Gerencia de Área. El cambio queda asentado en el historial.
                    </p>
                    <div class="form-grid">
                        <div class="field" style="grid-column:1 / -1;">
                            <label>Sector de destino <span style="color:var(--color-danger);">*</span></label>
                            <select v-model="transferData.sector_id" class="select" required>
                                <option :value="null">—</option>
                                <option v-for="g in sectoresDestino" :key="g.sector_id" :value="g.sector_id">
                                    {{ g.nombre }}<template v-if="g.dependencia"> · {{ g.dependencia.nombre }}</template>
                                </option>
                            </select>
                            <div v-if="transferErrors.sector_id" class="error">{{ transferErrors.sector_id[0] }}</div>
                        </div>
                        <div class="field" style="grid-column:1 / -1;">
                            <label>Motivo</label>
                            <textarea v-model="transferData.motivo" class="textarea" maxlength="500"
                                      placeholder="Ej.: reorganización de estructura, baja de la gerencia anterior…" />
                        </div>
                    </div>
                </form>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="transferOpen = false">Cancelar</button>
                    <button type="button" class="btn btn-primary" :disabled="transfiriendo || !transferData.sector_id"
                            @click="transferir">
                        {{ transfiriendo ? 'Transfiriendo…' : 'Transferir' }}
                    </button>
                </template>
            </BaseModal>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { contratosEjecucionService } from '@/services/contratosEjecucion';
import { listAll } from '@/services/catalogos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtDate, fmtMoney, badgeForEstado } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import HistorialPanel from '@/components/HistorialPanel.vue';
import BaseModal from '@/components/BaseModal.vue';
import MovimientosPanel from '@/components/MovimientosPanel.vue';
import Field from '@/components/DetailField.vue';

const route = useRoute();
const auth = useAuthStore();
const toast = useToast();

const c = ref(null);
const loading = ref(true);

// El detalle es la primera solapa, así que es la que abre.
const tab = ref('detalle');

function responsable(p) {
    if (!p) return '—';
    return `${p.apellido}, ${p.nombre}`;
}

async function refrescar() {
    try {
        const r = await contratosEjecucionService.get(route.params.id, { mostrar_baja: '1' });
        c.value = r.data;
    } catch { /* no-op */ }
}

// ---- Transferencia a otra gerencia (sólo administrador de sistema) ----
const transferOpen = ref(false);
const transfiriendo = ref(false);
const transferErrors = ref({});
const transferData = reactive({ sector_id: null, motivo: '' });
const sectores = ref([]);

const sectoresDestino = computed(() =>
    sectores.value.filter(s => s.sector_id !== c.value?.sector_id));

async function abrirTransferencia() {
    transferData.sector_id = null;
    transferData.motivo = '';
    transferErrors.value = {};
    if (!sectores.value.length) {
        try { sectores.value = await listAll('sectores'); } catch { /* no-op */ }
    }
    transferOpen.value = true;
}

async function transferir() {
    transferErrors.value = {};
    transfiriendo.value = true;
    try {
        await contratosEjecucionService.transferir(route.params.id, {
            sector_id: transferData.sector_id,
            motivo: transferData.motivo || undefined,
        });
        toast.success('Contrato transferido.');
        transferOpen.value = false;
        await refrescar();
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            transferErrors.value = err.response.data.errors;
        } else {
            toast.error(extractError(err, 'No se pudo transferir el contrato.'));
        }
    } finally {
        transfiriendo.value = false;
    }
}

onMounted(async () => {
    await refrescar();
    loading.value = false;
});
</script>

<style scoped>
.tabs {
    display: flex;
    gap: 4px;
    margin: 16px 0 0;
    border-bottom: 1px solid var(--color-border, #e3e6ea);
}
.tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 600;
    color: var(--color-muted, #888);
    cursor: pointer;
}
.tab:hover { color: var(--color-text, #222); }
.tab.activa {
    color: var(--color-primary, #1a2e4a);
    border-bottom-color: var(--color-accent, #4aa3c7);
}
</style>
