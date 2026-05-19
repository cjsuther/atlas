<template>
    <div>
        <h1 class="page-title">{{ isEdit ? 'Editar' : 'Nuevo' }} contrato de ejecución</h1>
        <p class="page-subtitle">Contrato concreto vinculado a un principal (CP / CIT / Convenio Específico)</p>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>

        <form v-else class="card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field full">
                    <label>Contrato principal vinculado *</label>
                    <select v-model="data.contrato_principal_id" class="select" @change="applyHerencia">
                        <option :value="null">— Sin vinculación (solo si tipo = AP)</option>
                        <option v-for="p in principales" :key="p.id" :value="p.id">
                            #{{ p.id }} · {{ p.nro_expediente }} — {{ p.nombre_proyecto }}
                        </option>
                    </select>
                    <div v-if="errors.contrato_principal_id" class="error">{{ errors.contrato_principal_id[0] }}</div>
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

                <!-- Heredados desde el principal (con marca visual) -->
                <div :class="['field', isInherited('gerencia_area') ? 'inherited' : '']">
                    <label>Gerencia / Área</label>
                    <input v-model="data.gerencia_area" class="input" />
                </div>
                <div :class="['field', isInherited('gerencia') ? 'inherited' : '']">
                    <label>Gerencia</label>
                    <input v-model="data.gerencia" class="input" />
                </div>

                <div :class="['field', isInherited('solicitante_id') ? 'inherited' : '']">
                    <label>Solicitante</label>
                    <select v-model="data.solicitante_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="s in solicitantes" :key="s.solicitante_id" :value="s.solicitante_id">
                            {{ s.razon_social }}
                        </option>
                    </select>
                </div>
                <div :class="['field', isInherited('utt_id') ? 'inherited' : '']">
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

                <div :class="['field', isInherited('resp1_id') ? 'inherited' : '']">
                    <label>Responsable 1</label>
                    <select v-model="data.resp1_id" class="select">
                        <option :value="null">—</option>
                        <option v-for="p in personal" :key="p.legajo" :value="p.legajo">
                            {{ p.apellido }}, {{ p.nombre }}
                        </option>
                    </select>
                </div>
                <div :class="['field', isInherited('resp2_id') ? 'inherited' : '']">
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
                    <label>Monto presupuestado · Ingresos</label>
                    <input v-model="data.monto_presupuestado_ingresos" type="number" step="0.01" class="input" />
                    <div v-if="errors.monto_presupuestado_ingresos" class="error">{{ errors.monto_presupuestado_ingresos[0] }}</div>
                </div>
                <div class="field">
                    <label>Monto presupuestado · Gastos</label>
                    <input v-model="data.monto_presupuestado_gastos" type="number" step="0.01" class="input" />
                    <div v-if="errors.monto_presupuestado_gastos" class="error">{{ errors.monto_presupuestado_gastos[0] }}</div>
                </div>
                <div class="field full" style="font-size:12px;color:var(--color-muted);">
                    Los montos <strong>ejecutados</strong> (ingresos y gastos) se calculan a partir de los movimientos
                    que se carguen en la sección "Ejecución de gastos e ingresos" del detalle de este contrato.
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
import { contratosPrincipalService } from '@/services/contratosPrincipal';
import { listAll } from '@/services/catalogos';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import ExpedienteInput from '@/components/ExpedienteInput.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const HERITABLE = ['gerencia_area', 'gerencia', 'solicitante_id', 'resp1_id', 'resp2_id', 'utt_id'];

const isEdit = computed(() => !!route.params.id);
const loading = ref(true);
const saving = ref(false);
const errors = ref({});
const inheritedMap = reactive({});  // { campo: true } cuando vino del principal

const data = reactive({
    nro_expediente: '',
    fecha_apertura_expediente: null,
    tipo_contrato_id: '',
    nombre_proyecto: '',
    descripcion_objeto: '',
    contrato_principal_id: null,
    gerencia_area: '',
    gerencia: '',
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
    monto_presupuestado_ingresos: null,
    monto_presupuestado_gastos: null,
});

const estados = ref([]);
const tipos = ref([]);
const solicitantes = ref([]);
const utts = ref([]);
const uvts = ref([]);
const personal = ref([]);
const principales = ref([]);

function isInherited(field) { return inheritedMap[field] === true; }

async function applyHerencia() {
    const pid = data.contrato_principal_id;
    for (const f of HERITABLE) inheritedMap[f] = false;
    if (!pid) return;
    try {
        const res = await contratosPrincipalService.get(pid);
        const p = res.data;
        for (const f of HERITABLE) {
            // Solo pre-completar si el campo del ejecución está vacío
            const empty = data[f] === null || data[f] === '' || data[f] === undefined;
            if (empty) {
                data[f] = p[f] ?? null;
                inheritedMap[f] = true;
            }
        }
    } catch { /* no-op */ }
}

// Si el usuario edita manualmente un campo heredado, quitamos la marca
for (const f of ['gerencia_area', 'gerencia']) {
    watch(() => data[f], () => { inheritedMap[f] = false; });
}
for (const f of ['solicitante_id', 'resp1_id', 'resp2_id', 'utt_id']) {
    watch(() => data[f], (_, prev) => {
        if (inheritedMap[f] && prev !== null && prev !== '') inheritedMap[f] = false;
    });
}

watch(() => data.moneda, (v) => { if (v === 'Peso') data.cotizacion = null; });

async function loadCatalogs() {
    const [e, t, s, u1, u2, p, pr] = await Promise.all([
        listAll('estados-ejecucion'),
        listAll('tipos-contrato-ejecucion'),
        listAll('solicitantes'),
        listAll('utt'),
        listAll('uvt'),
        listAll('personal'),
        listAll('contratos-principal', { per_page: 500 }),
    ]);
    estados.value = e; tipos.value = t; solicitantes.value = s;
    utts.value = u1; uvts.value = u2; personal.value = p;
    principales.value = pr;
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
        // Si vino ?principal=<id> en la query, pre-vincular y heredar
        if (!isEdit.value && route.query.principal) {
            data.contrato_principal_id = Number(route.query.principal);
            await applyHerencia();
        }
    } catch (err) {
        toast.error(extractError(err, 'Error al cargar el formulario.'));
    } finally {
        loading.value = false;
    }
});
</script>
