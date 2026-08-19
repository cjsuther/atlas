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
                    <router-link :to="{ name: 'contratos-ejecucion-detalle', params: { id: c.id } }"
                                 class="btn btn-primary">
                        Detalle
                    </router-link>
                    <router-link :to="{ name: 'contratos-ejecucion' }" class="btn btn-secondary">Volver</router-link>
                </div>
            </div>

            <!-- Situación del contrato: el saldo es lo que estos movimientos modifican. -->
            <div class="kpi-grid" style="margin-top:16px;">
                <div class="kpi-card">
                    <div class="label">Saldo inicial</div>
                    <div class="value money">{{ fmtMoney(c.saldo_inicial) }}</div>
                </div>
                <div class="kpi-card info">
                    <div class="label">Ingresos</div>
                    <div class="value money">{{ fmtMoney(c.monto_ejecutado_ingresos) }}</div>
                </div>
                <div class="kpi-card warning">
                    <div class="label">Gastos</div>
                    <div class="value money">{{ fmtMoney(c.monto_ejecutado_gastos) }}</div>
                </div>
                <div :class="['kpi-card', Number(c.saldo) < 0 ? 'danger' : 'success']">
                    <div class="label">Saldo ({{ c.moneda }})</div>
                    <div class="value money">{{ fmtMoney(c.saldo) }}</div>
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
import { fmtMoney, badgeForEstado } from '@/composables/useFormat';
import HistorialPanel from '@/components/HistorialPanel.vue';
import MovimientosPanel from '@/components/MovimientosPanel.vue';

const route = useRoute();

const c = ref(null);
const loading = ref(true);

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
