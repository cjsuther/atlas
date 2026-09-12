<template>
    <div>
        <h1 class="page-title">{{ isEdit ? 'Editar' : 'Nuevo' }} expediente</h1>
        <p class="page-subtitle">
            Todo expediente se imputa a una cuenta operativa de la estructura
        </p>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>

        <form v-else class="card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field" style="grid-column:1 / -1;">
                    <label>Cuenta operativa *</label>
                    <select v-model="data.cuenta_operativa_id" class="select" required>
                        <option :value="null">—</option>
                        <template v-for="o in opcionesCuenta" :key="o.key">
                            <option v-if="o.tipo === 'nodo'" disabled>{{ o.etiqueta }}</option>
                            <option v-else :value="o.id" :disabled="!o.permitida">
                                {{ o.etiqueta }}<template v-if="!o.permitida"> — sin permisos</template>
                            </option>
                        </template>
                    </select>
                    <div class="hint">
                        El expediente se imputa a una cuenta. La rama a la que pertenece sale
                        de dónde cuelga esa cuenta.
                    </div>
                    <div v-if="errors.cuenta_operativa_id" class="error">{{ errors.cuenta_operativa_id[0] }}</div>
                </div>

                <div class="field">
                    <label>Nº de expediente *</label>
                    <ExpedienteInput v-model="data.nro_expediente" />
                    <div v-if="errors.nro_expediente" class="error">{{ errors.nro_expediente[0] }}</div>
                </div>
                <div class="field">
                    <label>F. apertura expediente</label>
                    <input v-model="data.fecha_apertura_expediente" type="date" class="input" />
                </div>

                <div class="field">
                    <label>Tipo de contrato *</label>
                    <select v-model="data.tipo_contrato_id" class="select" required>
                        <option value="">—</option>
                        <option v-for="t in tipos" :key="t.id" :value="t.id">{{ t.sigla }} — {{ t.nombre }}</option>
                    </select>
                    <div v-if="errors.tipo_contrato_id" class="error">{{ errors.tipo_contrato_id[0] }}</div>
                </div>
                <div class="field">
                    <label>Estado *</label>
                    <select v-model="data.estado_id" class="select" required>
                        <option value="">—</option>
                        <option v-for="e in estados" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                    </select>
                    <div v-if="errors.estado_id" class="error">{{ errors.estado_id[0] }}</div>
                </div>

                <div class="field full">
                    <label>Nombre del proyecto *</label>
                    <input v-model="data.nombre_proyecto" class="input" required maxlength="500" />
                    <div v-if="errors.nombre_proyecto" class="error">{{ errors.nombre_proyecto[0] }}</div>
                </div>

                <div class="field full">
                    <label>Descripción del objeto</label>
                    <textarea v-model="data.descripcion_objeto" class="textarea" />
                </div>

                <div class="field">
                    <label>Solicitante</label>
                    <select v-model="data.solicitante_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="s in solicitantes" :key="s.solicitante_id" :value="s.solicitante_id">
                            {{ s.razon_social }}
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>UVT</label>
                    <select v-model="data.uvt_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="u in uvts" :key="u.uvt_id" :value="u.uvt_id">
                            {{ u.siglas }} — {{ u.nombre }}
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Responsable 1</label>
                    <select v-model="data.resp1_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="p in personal" :key="p.legajo" :value="p.legajo">
                            {{ p.apellido }}, {{ p.nombre }}
                        </option>
                    </select>
                </div>
                <div class="field">
                    <label>Responsable 2</label>
                    <select v-model="data.resp2_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="p in personal" :key="p.legajo" :value="p.legajo">
                            {{ p.apellido }}, {{ p.nombre }}
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Cliente / Contraparte</label>
                    <input v-model="data.cliente" class="input" />
                </div>

                <div class="field">
                    <label>F. Inicio</label>
                    <input v-model="data.fecha_inicio" type="date" class="input" />
                    <div v-if="errors.fecha_inicio" class="error">{{ errors.fecha_inicio[0] }}</div>
                </div>
                <div class="field">
                    <label>F. Vencimiento</label>
                    <input v-model="data.fecha_vencimiento" type="date" class="input" />
                    <div v-if="errors.fecha_vencimiento" class="error">{{ errors.fecha_vencimiento[0] }}</div>
                </div>
                <div class="field">
                    <label>F. Finalización</label>
                    <input v-model="data.fecha_finalizacion" type="date" class="input" />
                    <div v-if="errors.fecha_finalizacion" class="error">{{ errors.fecha_finalizacion[0] }}</div>
                </div>
                <div class="field">
                    <label>Acta de finalización</label>
                    <input v-model="data.acta_finalizacion" class="input" />
                </div>

                <div class="field">
                    <label>Prórroga</label>
                    <select v-model="data.prorroga" class="select">
                        <option :value="false">No</option>
                        <option :value="true">Sí</option>
                    </select>
                </div>
                <div class="field">
                    <label>Renovación automática</label>
                    <select v-model="data.renovacion_automatica" class="select">
                        <option :value="false">No</option>
                        <option :value="true">Sí</option>
                    </select>
                </div>

                <div class="field">
                    <label>Moneda *</label>
                    <select v-model="data.moneda" class="select" required>
                        <option>Peso</option>
                        <option>Dólar</option>
                        <option>Euro</option>
                        <option>Otro</option>
                    </select>
                </div>
                <div class="field">
                    <label>Cotización <span v-if="data.moneda !== 'Peso'" style="color:var(--color-danger);">*</span></label>
                    <input v-model="data.cotizacion" type="number" step="0.0001" class="input"
                           :disabled="data.moneda === 'Peso'" />
                    <div v-if="errors.cotizacion" class="error">{{ errors.cotizacion[0] }}</div>
                </div>

                <div class="field">
                    <label>Saldo inicial</label>
                    <input v-model="data.saldo_inicial" type="number" step="0.01" class="input" />
                    <div class="hint">Monto con el que arranca el contrato. Puede ser negativo.</div>
                    <div v-if="errors.saldo_inicial" class="error">{{ errors.saldo_inicial[0] }}</div>
                </div>
                <div class="field full" style="font-size:12px;color:var(--color-muted);">
                    El <strong>saldo</strong> del contrato es el saldo inicial más los ingresos y menos los gastos
                    que se carguen en la solapa "Ejecución" de su detalle.
                </div>
                <div class="field">
                    <label>Caja BAS</label>
                    <input v-model="data.caja_bas" class="input" />
                </div>

                <div class="field full">
                    <label>Observaciones</label>
                    <textarea v-model="data.observaciones" class="textarea" />
                </div>
            </div>

            <div class="modal-footer" style="border-top:1px solid var(--color-border);margin-top:18px;padding:14px 0 0;">
                <button type="button" class="btn btn-secondary" @click="$router.back()">Cancelar</button>
                <button type="submit" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="loader" /> {{ saving ? 'Guardando…' : 'Guardar' }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { contratosEjecucionService } from '@/services/contratosEjecucion';
import { listAll } from '@/services/catalogos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import ExpedienteInput from '@/components/ExpedienteInput.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const auth = useAuthStore();

const isEdit = computed(() => !!route.params.id);
const loading = ref(true);
const saving = ref(false);
const errors = ref({});

const data = reactive({
    nro_expediente: '',
    fecha_apertura_expediente: null,
    tipo_contrato_id: '',
    nombre_proyecto: '',
    descripcion_objeto: '',
    cuenta_operativa_id: null,
    solicitante_id: null,
    resp1_id: null,
    resp2_id: null,
    estado_id: '',
    observaciones: '',
    uvt_id: null,
    cliente: '',
    fecha_inicio: null,
    fecha_vencimiento: null,
    fecha_finalizacion: null,
    acta_finalizacion: '',
    prorroga: false,
    renovacion_automatica: false,
    caja_bas: '',
    moneda: 'Peso',
    cotizacion: null,
    saldo_inicial: null,
});

const estados = ref([]);
const tipos = ref([]);
const solicitantes = ref([]);
const uvts = ref([]);
const personal = ref([]);
const arbol = ref(null);

/**
 * El árbol aplanado para el desplegable: cada nodo como encabezado y sus
 * cuentas debajo, sangradas según el nivel. El servidor marca cuáles puede
 * usar el usuario; el resto se muestran deshabilitadas, para que se vea la
 * estructura completa sin poder imputar fuera de la propia rama.
 */
const opcionesCuenta = computed(() => {
    const filas = [];
    const recorrer = (nodo, nivel) => {
        const sangria = '\u00A0\u00A0'.repeat(nivel);
        filas.push({
            key: `n-${nodo.sector_id ?? 'raiz'}`,
            tipo: 'nodo',
            etiqueta: `${sangria}${nodo.nombre}`,
        });
        for (const c of nodo.cuentas) {
            filas.push({
                key: `c-${c.id}`,
                tipo: 'cuenta',
                id: c.id,
                permitida: c.permitida,
                etiqueta: `${sangria}\u00A0\u00A0· ${c.nombre}`,
            });
        }
        for (const h of nodo.hijos) recorrer(h, nivel + 1);
    };
    if (arbol.value) recorrer(arbol.value, 0);
    return filas;
});

watch(() => data.moneda, (v) => { if (v === 'Peso') data.cotizacion = null; });

async function loadCatalogs() {
    const [e, t, s, u, p, arb] = await Promise.all([
        listAll('estados-ejecucion'),
        listAll('tipos-contrato-ejecucion'),
        listAll('solicitantes'),
        listAll('uvt'),
        listAll('personal'),
        contratosEjecucionService.arbolEstructura(),
    ]);
    estados.value = e; tipos.value = t; solicitantes.value = s;
    uvts.value = u; personal.value = p; arbol.value = arb;
}

async function loadContrato() {
    if (!isEdit.value) return;
    const res = await contratosEjecucionService.get(route.params.id);
    const d = res.data;
    for (const k of Object.keys(data)) {
        if (k in d) data[k] = d[k];
    }
}

async function submit() {
    errors.value = {};
    saving.value = true;
    try {
        const payload = JSON.parse(JSON.stringify(data));
        for (const k of ['fecha_apertura_expediente','fecha_inicio','fecha_vencimiento','fecha_finalizacion']) {
            if (!payload[k]) payload[k] = null;
        }
        if (isEdit.value) {
            await contratosEjecucionService.update(route.params.id, payload);
            toast.success('Contrato actualizado.');
            router.replace({ name: 'contratos-ejecucion-detalle', params: { id: route.params.id } });
        } else {
            const res = await contratosEjecucionService.create(payload);
            toast.success('Contrato creado.');
            router.replace({ name: 'contratos-ejecucion-detalle', params: { id: res.data.id } });
        }
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            errors.value = err.response.data.errors;
            toast.error('Hay errores de validación en el formulario.');
        } else {
            toast.error(extractError(err, 'No se pudo guardar.'));
        }
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        await loadCatalogs();
        await loadContrato();
    } catch (err) {
        toast.error(extractError(err, 'Error al cargar el formulario.'));
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
.hint { font-size: 12px; color: var(--color-muted, #888); margin-top: 4px; }
</style>
