<template>
    <div>
        <h1 class="page-title">{{ isEdit ? 'Editar' : 'Nuevo' }} contrato principal</h1>
        <p class="page-subtitle">Datos del acuerdo marco (CATP / CLIT / AP)</p>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>

        <form v-else class="card" @submit.prevent="submit">
            <div class="form-grid">
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
                    <label>Régimen *</label>
                    <select v-model="data.regimen" class="select" required>
                        <option value="">—</option>
                        <option value="160">160</option>
                        <option value="317">317</option>
                    </select>
                    <div v-if="errors.regimen" class="error">{{ errors.regimen[0] }}</div>
                </div>

                <div class="field">
                    <label>Tipo de contrato *</label>
                    <select v-model="data.tipo_contrato_id" class="select" required>
                        <option value="">—</option>
                        <option v-for="t in tipos" :key="t.id" :value="t.id">{{ t.sigla }} — {{ t.nombre }}</option>
                    </select>
                    <div v-if="errors.tipo_contrato_id" class="error">{{ errors.tipo_contrato_id[0] }}</div>
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
                    <label>Gerencia / Área</label>
                    <input v-model="data.gerencia_area" class="input" />
                </div>
                <div class="field">
                    <label>Gerencia</label>
                    <input v-model="data.gerencia" class="input" />
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
                    <label>Estado *</label>
                    <select v-model="data.estado_id" class="select" required>
                        <option value="">—</option>
                        <option v-for="e in estados" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                    </select>
                    <div v-if="errors.estado_id" class="error">{{ errors.estado_id[0] }}</div>
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
                    <div v-if="errors.prorroga" class="error">{{ errors.prorroga[0] }}</div>
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
import { computed, onMounted, ref, reactive, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { contratosPrincipalService } from '@/services/contratosPrincipal';
import { listAll } from '@/services/catalogos';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import ExpedienteInput from '@/components/ExpedienteInput.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const isEdit = computed(() => !!route.params.id);
const loading = ref(true);
const saving = ref(false);
const errors = ref({});

const data = reactive({
    nro_expediente: '',
    fecha_apertura_expediente: null,
    regimen: '',
    tipo_contrato_id: '',
    nombre_proyecto: '',
    descripcion_objeto: '',
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
});

const estados = ref([]);
const tipos = ref([]);
const solicitantes = ref([]);
const utts = ref([]);
const uvts = ref([]);
const personal = ref([]);

watch(() => data.moneda, (v) => {
    if (v === 'Peso') data.cotizacion = null;
});

async function loadCatalogs() {
    const [e, t, s, u1, u2, p] = await Promise.all([
        listAll('estados-principal'),
        listAll('tipos-contrato-principal'),
        listAll('solicitantes'),
        listAll('utt'),
        listAll('uvt'),
        listAll('personal'),
    ]);
    estados.value = e; tipos.value = t; solicitantes.value = s;
    utts.value = u1; uvts.value = u2; personal.value = p;
}

async function loadContrato() {
    if (!isEdit.value) return;
    const res = await contratosPrincipalService.get(route.params.id);
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
        // Convertir vacíos a null donde corresponde
        for (const k of ['fecha_apertura_expediente','fecha_inicio','fecha_vencimiento','fecha_finalizacion']) {
            if (!payload[k]) payload[k] = null;
        }
        if (isEdit.value) {
            await contratosPrincipalService.update(route.params.id, payload);
            toast.success('Contrato actualizado.');
        } else {
            const res = await contratosPrincipalService.create(payload);
            toast.success('Contrato creado.');
            router.replace({ name: 'contratos-principal-detalle', params: { id: res.data.id } });
            return;
        }
        router.replace({ name: 'contratos-principal-detalle', params: { id: route.params.id } });
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
