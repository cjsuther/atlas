<template>
    <div>
        <h1 class="page-title">{{ isEdit ? 'Editar' : 'Nuevo' }} contrato</h1>
        <p class="page-subtitle">
            Todo contrato pertenece a una gerencia, y ésta a una Gerencia de Área
        </p>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>

        <form v-else class="card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label>Gerencia de Área *</label>
                    <select v-model="areaSeleccionada" class="select" :disabled="!auth.isAdminSistema" required>
                        <option :value="null">—</option>
                        <option v-for="a in areas" :key="a.sector_id" :value="a.sector_id">{{ a.nombre }}</option>
                    </select>
                    <div v-if="!auth.isAdminSistema" class="hint">
                        Sólo puede cargar contratos en su Gerencia de Área.
                    </div>
                </div>
                <div class="field">
                    <label>Sector *</label>
                    <select v-model="data.sector_id" class="select" required :disabled="!areaSeleccionada">
                        <option :value="null">—</option>
                        <option v-for="g in sectoresDisponibles" :key="g.sector_id" :value="g.sector_id">
                            {{ g.nombre }}<template v-if="g.sector_id === areaSeleccionada"> (la Gerencia de Área)</template>
                        </option>
                    </select>
                    <div class="hint">Subsector al que se imputa el contrato.</div>
                    <div v-if="errors.sector_id" class="error">{{ errors.sector_id[0] }}</div>
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
                    <label>UTT</label>
                    <select v-model="data.utt_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="u in utts" :key="u.utt_id" :value="u.utt_id">
                            {{ u.denominacion }} — {{ u.nombre }}
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
    sector_id: null,
    solicitante_id: null,
    resp1_id: null,
    resp2_id: null,
    utt_id: null,
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
const utts = ref([]);
const uvts = ref([]);
const personal = ref([]);
const sectores = ref([]);
const areaSeleccionada = ref(null);

/** Los sectores sin dependencia son las Gerencias de Área. */
const areas = computed(() => sectores.value.filter(s => s.dependencia_id === null));

/** La Gerencia de Área elegida, más sus subsectores. */
const sectoresDisponibles = computed(() => {
    if (!areaSeleccionada.value) return [];
    return sectores.value.filter(s =>
        s.sector_id === areaSeleccionada.value
        || String(s.dependencia_id) === String(areaSeleccionada.value));
});

// Al cambiar de Gerencia de Área, se descarta el sector si ya no pertenece a ella.
watch(areaSeleccionada, () => {
    if (!data.sector_id) return;
    const sigue = sectoresDisponibles.value.some(s => s.sector_id === data.sector_id);
    if (!sigue) data.sector_id = null;
});

watch(() => data.moneda, (v) => { if (v === 'Peso') data.cotizacion = null; });

async function loadCatalogs() {
    const [e, t, s, u1, u2, p, sec] = await Promise.all([
        listAll('estados-ejecucion'),
        listAll('tipos-contrato-ejecucion'),
        listAll('solicitantes'),
        listAll('utt'),
        listAll('uvt'),
        listAll('personal'),
        listAll('sectores'),
    ]);
    estados.value = e; tipos.value = t; solicitantes.value = s;
    utts.value = u1; uvts.value = u2; personal.value = p;
    sectores.value = sec;
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

        // Un usuario acotado sólo carga contratos en su propia Gerencia de Área.
        if (!isEdit.value && !auth.isAdminSistema && auth.sectorId) {
            areaSeleccionada.value = auth.sectorId;
        } else {
            const sector = sectores.value.find(s => s.sector_id === data.sector_id);
            areaSeleccionada.value = sector
                ? (sector.dependencia_id ?? sector.sector_id)
                : null;
        }
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
