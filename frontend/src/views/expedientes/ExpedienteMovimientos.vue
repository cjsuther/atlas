<template>
    <div>
        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!c" class="empty-state">Expediente no encontrado.</div>

        <div v-else>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1 class="page-title">Expediente {{ c.nro_expediente }}</h1>
                    <p class="page-subtitle">
                        #{{ c.id }} · {{ c.cuenta_operativa?.ruta }}
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <router-link :to="{ name: 'expedientes-detalle', params: { id: c.id } }"
                                 class="btn btn-primary">
                        Detalle
                    </router-link>
                    <router-link :to="{ name: 'expedientes' }" class="btn btn-secondary">Volver</router-link>
                </div>
            </div>

            <!-- Lo que se registró contra este expediente. El saldo disponible
                 no es del expediente sino de su cuenta. -->
            <div class="kpi-grid" style="margin-top:16px;">
                <div class="kpi-card info">
                    <div class="label">Ingresos relacionados</div>
                    <div class="value money">{{ fmtMoney(c.monto_ejecutado_ingresos) }}</div>
                </div>
                <div class="kpi-card warning">
                    <div class="label">Gastos relacionados</div>
                    <div class="value money">{{ fmtMoney(c.monto_ejecutado_gastos) }}</div>
                </div>
                <div :class="['kpi-card', Number(c.saldo) < 0 ? 'danger' : 'success']">
                    <div class="label">Resultado</div>
                    <div class="value money">{{ fmtMoney(c.saldo) }}</div>
                </div>
                <div class="kpi-card">
                    <div class="label">Cuenta</div>
                    <div class="value" style="font-size:15px;">
                        <router-link v-if="c.cuenta_operativa"
                                     :to="{ name: 'cuenta-detalle', params: { id: c.cuenta_operativa.id } }">
                            {{ c.cuenta_operativa.nombre }}
                        </router-link>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>

            <MovimientosPanel :expediente-id="c.id" @changed="refrescar" />
            <HistorialPanel tabla="expedientes" :id="c.id" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { expedientesService } from '@/services/expedientes';
import { fmtMoney } from '@/composables/useFormat';
import HistorialPanel from '@/components/HistorialPanel.vue';
import MovimientosPanel from '@/components/MovimientosPanel.vue';

const route = useRoute();

const c = ref(null);
const loading = ref(true);

async function refrescar() {
    try {
        const r = await expedientesService.get(route.params.id, { mostrar_baja: '1' });
        c.value = r.data;
    } catch { /* no-op */ }
}

onMounted(async () => {
    await refrescar();
    loading.value = false;
});
</script>
