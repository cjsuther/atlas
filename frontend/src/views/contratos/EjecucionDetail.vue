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
                        <span v-if="c.principal">
                            · vinculado a
                            <router-link :to="{ name: 'contratos-principal-detalle', params: { id: c.principal.id } }">
                                Principal #{{ c.principal.id }} ({{ c.principal.nro_expediente }})
                            </router-link>
                        </span>
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <router-link v-if="auth.canEdit && !c.deleted_at"
                                 :to="{ name: 'contratos-ejecucion-editar', params: { id: c.id } }"
                                 class="btn btn-primary">
                        <IconLib name="edit" /> Editar
                    </router-link>
                    <router-link :to="{ name: 'contratos-ejecucion' }" class="btn btn-secondary">Volver</router-link>
                </div>
            </div>

            <div class="card">
                <div class="detail-section">
                    <h4>Identificación</h4>
                    <div class="detail-grid">
                        <Field label="Expediente" :value="c.nro_expediente" />
                        <Field label="F. Apertura" :value="fmtDate(c.fecha_apertura_expediente)" />
                        <Field label="Tipo" :value="c.tipo_contrato?.sigla + ' — ' + c.tipo_contrato?.nombre" />
                        <Field label="Estado" :value="c.estado?.nombre" />
                        <Field label="Contrato Principal" :value="c.principal ? `#${c.principal.id} — ${c.principal.nombre_proyecto}` : '—'" />
                        <Field label="UTT" :value="c.utt ? `${c.utt.denominacion} — ${c.utt.nombre}` : '—'" />
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Áreas y responsables</h4>
                    <div class="detail-grid">
                        <Field label="Gerencia" :value="c.gerencia" />
                        <Field label="Gerencia / Área" :value="c.gerencia_area" />
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
                        <Field label="Presupuestado · Ingresos"   :value="fmtMoney(c.monto_presupuestado_ingresos)" />
                        <Field label="Presupuestado · Gastos"     :value="fmtMoney(c.monto_presupuestado_gastos)" />
                        <Field label="Ejecutado · Ingresos (suma mov.)" :value="fmtMoney(c.monto_ejecutado_ingresos)" />
                        <Field label="Ejecutado · Gastos (suma mov.)"   :value="fmtMoney(c.monto_ejecutado_gastos)" />
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

            <MovimientosPanel :contrato-ejecucion-id="c.id" @changed="refrescar" />
            <HistorialPanel tabla="contratos_ejecucion" :id="c.id" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { contratosEjecucionService } from '@/services/contratosEjecucion';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtDate, fmtMoney, badgeForEstado } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import HistorialPanel from '@/components/HistorialPanel.vue';
import MovimientosPanel from '@/components/MovimientosPanel.vue';
import Field from '@/components/DetailField.vue';

const route = useRoute();
const auth = useAuthStore();
const toast = useToast();

const c = ref(null);
const loading = ref(true);

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

onMounted(async () => {
    await refrescar();
    loading.value = false;
});
</script>
