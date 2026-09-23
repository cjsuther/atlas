<template>
    <div>
        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!cuenta" class="empty-state">Cuenta no encontrada.</div>

        <div v-else>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1 class="page-title">
                        <span v-if="!cuenta.activo" class="badge badge-default">inactiva</span>
                        {{ cuenta.nombre }}
                    </h1>
                    <p class="page-subtitle">
                        {{ NIVELES[cuenta.nivel] || cuenta.nivel }} · {{ cuenta.ruta }}
                    </p>
                </div>
                <router-link :to="{ name: 'catalogo', params: { slug: 'cuentas-operativas' } }"
                             class="btn btn-secondary">Volver</router-link>
            </div>

            <!-- El saldo de la cuenta es lo que estos movimientos modifican. -->
            <div class="kpi-grid" style="margin-top:16px;">
                <div class="kpi-card">
                    <div class="label">Saldo inicial</div>
                    <div class="value money">{{ fmtMoney(cuenta.saldo_inicial) }}</div>
                </div>
                <div class="kpi-card info">
                    <div class="label">Ingresos</div>
                    <div class="value money">{{ fmtMoney(cuenta.ingresos) }}</div>
                </div>
                <div class="kpi-card warning">
                    <div class="label">Gastos</div>
                    <div class="value money">{{ fmtMoney(cuenta.gastos) }}</div>
                </div>
                <div :class="['kpi-card', Number(cuenta.saldo) < 0 ? 'danger' : 'success']">
                    <div class="label">Saldo</div>
                    <div class="value money">{{ fmtMoney(cuenta.saldo) }}</div>
                </div>
            </div>

            <p v-if="cuenta.descripcion" style="margin:14px 0 0;color:var(--color-muted);">
                {{ cuenta.descripcion }}
            </p>

            <MovimientosPanel :cuenta-operativa-id="cuenta.id" @changed="refrescar" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { cuentasService } from '@/services/cuentas';
import { fmtMoney } from '@/composables/useFormat';
import MovimientosPanel from '@/components/MovimientosPanel.vue';

/** Etiquetas de los niveles del árbol de la estructura. */
const NIVELES = {
    organizacion:  'Toda la organización',
    gerencia_area: 'Gerencia de Área',
    gerencia:      'Gerencia',
    contrato:      'Contrato',
};

const route = useRoute();

const cuenta = ref(null);
const loading = ref(true);

async function refrescar() {
    try {
        const r = await cuentasService.get(route.params.id);
        cuenta.value = r.data;
    } catch {
        cuenta.value = null;
    }
}

onMounted(async () => {
    await refrescar();
    loading.value = false;
});

watch(() => route.params.id, refrescar);
</script>
