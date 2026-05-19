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
                        #{{ c.id }} · {{ c.nro_expediente }} ·
                        Régimen {{ c.regimen }} · {{ c.tipo_contrato?.sigla }}
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <router-link v-if="auth.canEdit && !c.deleted_at"
                                 :to="{ name: 'contratos-principal-editar', params: { id: c.id } }"
                                 class="btn btn-primary">
                        <IconLib name="edit" /> Editar
                    </router-link>
                    <router-link :to="{ name: 'contratos-principal' }" class="btn btn-secondary">Volver</router-link>
                </div>
            </div>

            <div class="card">
                <div class="detail-section">
                    <h4>Identificación</h4>
                    <div class="detail-grid">
                        <Field label="Expediente" :value="c.nro_expediente" />
                        <Field label="F. Apertura" :value="fmtDate(c.fecha_apertura_expediente)" />
                        <Field label="Régimen" :value="c.regimen" />
                        <Field label="Tipo" :value="c.tipo_contrato?.sigla + ' — ' + c.tipo_contrato?.nombre" />
                        <Field label="Estado" :value="c.estado?.nombre" />
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
                        <Field label="Ejecutado · Ingresos (suma movimientos)" :value="fmtMoney(c.monto_ejecutado_ingresos)" />
                        <Field label="Ejecutado · Gastos (suma movimientos)"   :value="fmtMoney(c.monto_ejecutado_gastos)" />
                        <Field label="Beneficio (ingresos − gastos)"           :value="fmtMoney(c.monto_beneficio)" />
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

            <!-- Ejecuciones vinculadas -->
            <details class="collapsible" open>
                <summary>
                    Contratos de ejecución vinculados
                    <span style="font-weight:400;opacity:0.9;font-size:13px;">
                        ({{ c.ejecuciones?.length || 0 }})
                    </span>
                </summary>
                <div class="collapsible-body">
                    <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
                        <router-link v-if="auth.canEdit"
                                     :to="{ name: 'contratos-ejecucion-nuevo', query: { principal: c.id } }"
                                     class="btn btn-primary btn-sm">
                            <IconLib name="plus" :size="14" /> Nueva ejecución
                        </router-link>
                    </div>
                    <table class="atlas-table" v-if="c.ejecuciones?.length">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Expediente</th>
                                <th>Tipo</th>
                                <th>Proyecto</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in c.ejecuciones" :key="e.id">
                                <td>{{ e.id }}</td>
                                <td>{{ e.nro_expediente }}</td>
                                <td>{{ e.tipo_contrato?.sigla }}</td>
                                <td>{{ e.nombre_proyecto }}</td>
                                <td><span :class="['badge', badgeForEstado(e.estado)]">{{ e.estado?.nombre }}</span></td>
                                <td class="actions">
                                    <router-link :to="{ name: 'contratos-ejecucion-detalle', params: { id: e.id } }">
                                        <button><IconLib name="eye" :size="14" /></button>
                                    </router-link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="empty-state">No hay contratos de ejecución vinculados.</div>
                </div>
            </details>

            <HistorialPanel tabla="contratos_principal" :id="c.id" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { contratosPrincipalService } from '@/services/contratosPrincipal';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtDate, fmtMoney, badgeForEstado } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import HistorialPanel from '@/components/HistorialPanel.vue';
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

onMounted(async () => {
    try {
        const r = await contratosPrincipalService.get(route.params.id, { mostrar_baja: '1' });
        c.value = r.data;
    } catch (err) {
        toast.error(extractError(err, 'No se pudo cargar el contrato.'));
    } finally {
        loading.value = false;
    }
});
</script>
