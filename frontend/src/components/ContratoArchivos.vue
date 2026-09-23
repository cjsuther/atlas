<template>
    <section class="contrato-archivos">
        <h4>Archivos <span v-if="archivos.length" class="cantidad">{{ archivos.length }}</span></h4>

        <form v-if="editable" class="subir" @submit.prevent="subir">
            <input ref="inputRef" type="file" class="input" :accept="ACEPTA" @change="elegir" />
            <input v-model="descripcion" type="text" class="input" maxlength="500"
                   placeholder="Descripción (opcional): convenio firmado, acta, anexo…" />
            <button type="submit" class="btn btn-primary btn-sm" :disabled="!archivo || subiendo">
                <IconLib name="upload" :size="14" /> {{ subiendo ? 'Subiendo…' : 'Subir' }}
            </button>
        </form>
        <div v-if="editable" class="hint">Documentos, planillas, imágenes o comprimidos, hasta 20 MB.</div>
        <div v-if="error" class="error">{{ error }}</div>

        <div v-if="cargando" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!archivos.length" class="vacio">El contrato no tiene archivos.</div>
        <table v-else class="atlas-table">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Descripción</th>
                    <th style="text-align:right;">Tamaño</th>
                    <th>Subido</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in archivos" :key="a.id">
                    <td>
                        <a href="#" @click.prevent="descargar(a)" :title="`Descargar ${a.nombre_original}`">
                            {{ a.nombre_original }}
                        </a>
                    </td>
                    <td>{{ a.descripcion || '—' }}</td>
                    <td style="text-align:right;white-space:nowrap;">{{ tamano(a.tamano) }}</td>
                    <td style="white-space:nowrap;">
                        {{ fmtDateTime(a.created_at) }}
                        <div v-if="a.subido_por" class="quien">{{ a.subido_por }}</div>
                    </td>
                    <td class="actions">
                        <button type="button" @click="descargar(a)" title="Descargar">
                            <IconLib name="download" :size="14" />
                        </button>
                        <button v-if="editable" type="button" class="danger" @click="quitar(a)" title="Quitar">
                            <IconLib name="trash" :size="14" />
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <ConfirmDialog ref="confirmRef" />
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { contratoArchivosService as api } from '@/services/contratos';
import { extractError } from '@/services/http';
import { fmtDateTime } from '@/composables/useFormat';
import { useToast } from '@/composables/useToast';
import IconLib from '@/components/IconLib.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

/**
 * Archivos adjuntos de un contrato. Se suben y se quitan en el momento, sin
 * esperar a que se guarde la ficha.
 */
const props = defineProps({
    fila:     { type: Object, required: true },
    editable: { type: Boolean, default: false },
});
const emit = defineEmits(['cambio']);

/** Lo mismo que admite el servidor; él es el que decide. */
const ACEPTA = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,.rtf,'
             + '.jpg,.jpeg,.png,.gif,.zip,.rar,.7z';

const toast = useToast();
const archivos = ref([]);
const cargando = ref(false);
const archivo = ref(null);
const descripcion = ref('');
const subiendo = ref(false);
const error = ref('');
const inputRef = ref(null);
const confirmRef = ref(null);

async function cargar() {
    cargando.value = true;
    try {
        archivos.value = await api.list(props.fila.sector_id);
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los archivos.'));
    } finally {
        cargando.value = false;
    }
}

function elegir(e) {
    archivo.value = e.target.files?.[0] || null;
    error.value = '';
}

async function subir() {
    if (!archivo.value) return;
    subiendo.value = true;
    error.value = '';
    try {
        await api.subir(props.fila.sector_id, archivo.value, descripcion.value.trim());
        toast.success('Archivo subido.');
        archivo.value = null;
        descripcion.value = '';
        if (inputRef.value) inputRef.value.value = '';
        await cargar();
        emit('cambio');
    } catch (err) {
        error.value = err?.response?.data?.errors?.archivo?.[0]
            || extractError(err, 'No se pudo subir el archivo.');
    } finally {
        subiendo.value = false;
    }
}

async function descargar(a) {
    try {
        await api.descargar(a);
    } catch (err) {
        toast.error(extractError(err, 'No se pudo descargar el archivo.'));
    }
}

async function quitar(a) {
    const ok = await confirmRef.value.show({
        title: 'Quitar archivo',
        message: `¿Quitar «${a.nombre_original}» del contrato? El archivo se borra del servidor.`,
        confirmText: 'Quitar',
        danger: true,
    });
    if (!ok) return;
    try {
        await api.quitar(a.id);
        toast.success('Archivo quitado.');
        await cargar();
        emit('cambio');
    } catch (err) {
        toast.error(extractError(err, 'No se pudo quitar el archivo.'));
    }
}

function tamano(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

onMounted(cargar);
</script>

<style scoped>
.contrato-archivos { margin-top: 20px; border-top: 1px solid var(--color-border, #e5e5e5); padding-top: 16px; }
.contrato-archivos h4 { margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
.cantidad { font-size: 12px; font-weight: 400; color: var(--color-muted, #888); }
.subir { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.subir .input { flex: 1 1 220px; }
.vacio { color: var(--color-muted, #888); font-size: 13px; padding: 8px 0; }
.quien { font-size: 11px; color: var(--color-muted, #888); }
.error { color: var(--color-danger); font-size: 12px; margin-top: 4px; }
.hint { color: var(--color-muted, #888); font-size: 12px; margin: 4px 0 10px; }
table { margin-top: 10px; }
</style>
