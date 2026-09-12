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
                    <router-link :to="{ name: 'contratos-ejecucion-movimientos', params: { id: c.id } }"
                                 class="btn btn-primary">
                        Ejecución
                    </router-link>
                    <button v-if="auth.isAdmin && !c.deleted_at" class="btn btn-secondary"
                            @click="abrirTransferencia">
                        Transferir a otra cuenta
                    </button>
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
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Áreas y responsables</h4>
                    <div class="detail-grid">
                        <Field label="Gerencia de Área" :value="c.gerencia_area?.nombre" />
                        <Field label="Cuenta operativa" :value="c.cuenta_operativa?.nombre" />
                        <Field label="Ubicación en la estructura" :value="c.cuenta_operativa?.ruta" />
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


            <!-- Transferencia completa del expediente a otra cuenta operativa -->
            <BaseModal v-model="transferOpen" title="Transferir expediente a otra cuenta">
                <form @submit.prevent="transferir">
                    <p style="margin-top:0;font-size:13px;color:var(--color-muted);">
                        El expediente y todos sus movimientos de ejecución pasan a la cuenta de destino,
                        que puede estar en otra Gerencia de Área. El cambio queda asentado en el historial.
                    </p>
                    <div class="form-grid">
                        <div class="field" style="grid-column:1 / -1;">
                            <label>Cuenta de destino <span style="color:var(--color-danger);">*</span></label>
                            <select v-model="transferData.cuenta_operativa_id" class="select" required>
                                <option :value="null">—</option>
                                <template v-for="o in opcionesCuenta" :key="o.key">
                                    <option v-if="o.tipo === 'nodo'" disabled>{{ o.etiqueta }}</option>
                                    <option v-else :value="o.id" :disabled="o.id === c?.cuenta_operativa_id">
                                        {{ o.etiqueta }}
                                    </option>
                                </template>
                            </select>
                            <div v-if="transferErrors.cuenta_operativa_id" class="error">
                                {{ transferErrors.cuenta_operativa_id[0] }}
                            </div>
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
                    <button type="button" class="btn btn-primary" :disabled="transfiriendo || !transferData.cuenta_operativa_id"
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
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtDate, fmtMoney, badgeForEstado } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import BaseModal from '@/components/BaseModal.vue';
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

// ---- Transferencia a otra gerencia (sólo administrador de sistema) ----
const transferOpen = ref(false);
const transfiriendo = ref(false);
const transferErrors = ref({});
const transferData = reactive({ cuenta_operativa_id: null, motivo: '' });
const arbol = ref(null);

/** El árbol aplanado: cada nodo como encabezado y sus cuentas debajo. */
const opcionesCuenta = computed(() => {
    const filas = [];
    const recorrer = (nodo, nivel) => {
        const sangria = '\u00A0\u00A0'.repeat(nivel);
        filas.push({ key: `n-${nodo.sector_id ?? 'raiz'}`, tipo: 'nodo', etiqueta: `${sangria}${nodo.nombre}` });
        for (const cta of nodo.cuentas) {
            filas.push({ key: `c-${cta.id}`, tipo: 'cuenta', id: cta.id,
                         etiqueta: `${sangria}\u00A0\u00A0· ${cta.nombre}` });
        }
        for (const h of nodo.hijos) recorrer(h, nivel + 1);
    };
    if (arbol.value) recorrer(arbol.value, 0);
    return filas;
});

async function abrirTransferencia() {
    transferData.cuenta_operativa_id = null;
    transferData.motivo = '';
    transferErrors.value = {};
    if (!arbol.value) {
        try { arbol.value = await contratosEjecucionService.arbolEstructura(); } catch { /* no-op */ }
    }
    transferOpen.value = true;
}

async function transferir() {
    transferErrors.value = {};
    transfiriendo.value = true;
    try {
        await contratosEjecucionService.transferir(route.params.id, {
            cuenta_operativa_id: transferData.cuenta_operativa_id,
            motivo: transferData.motivo || undefined,
        });
        toast.success('Expediente transferido.');
        transferOpen.value = false;
        await refrescar();
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            transferErrors.value = err.response.data.errors;
        } else {
            toast.error(extractError(err, 'No se pudo transferir el expediente.'));
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

