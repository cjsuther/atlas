<template>
    <div>
        <h1 class="page-title">Exportar / Importar base de datos</h1>
        <p class="page-subtitle">Backup completo en Excel y carga de datos por clave (combina sin borrar)</p>

        <!-- Exportar -->
        <div class="filters-panel">
            <h3 class="block-title"><IconLib name="download" :size="16" /> Exportar base de datos</h3>
            <p class="block-text">
                Descarga un Excel con una solapa por tabla y las columnas técnicas (IDs y claves foráneas en crudo).
                Es el formato que requiere la importación. Las contraseñas no se incluyen.
            </p>
            <button class="btn btn-primary" :disabled="exporting" @click="exportar">
                <span v-if="exporting" class="loader" />
                <IconLib v-else name="download" />
                {{ exporting ? 'Generando…' : 'Exportar base de datos' }}
            </button>
        </div>

        <!-- Importar -->
        <div class="filters-panel" style="margin-top:20px;">
            <h3 class="block-title"><IconLib name="upload" :size="16" /> Importar base de datos</h3>
            <p class="block-text">
                Sube un Excel con la estructura del export. Por cada fila: si el ID ya existe se <strong>actualiza</strong>;
                si no, se <strong>inserta</strong>. No se borra nada que no esté en el archivo. Todo se aplica en una sola
                transacción (si algo falla, no se aplica ningún cambio).
            </p>

            <div class="field" style="max-width:420px;">
                <label>Archivo Excel (.xlsx)</label>
                <input ref="fileInput" type="file" accept=".xlsx,.xls" class="input" @change="onFile" />
            </div>

            <button class="btn btn-primary" :disabled="!archivo || importing" @click="confirmarImportar">
                <span v-if="importing" class="loader" />
                <IconLib v-else name="upload" />
                {{ importing ? 'Importando…' : 'Importar' }}
            </button>

            <!-- Resumen -->
            <div v-if="resumen.length" class="table-wrapper" style="margin-top:20px;">
                <table class="atlas-table">
                    <thead>
                        <tr>
                            <th>Tabla</th>
                            <th>Insertados</th>
                            <th>Actualizados</th>
                            <th>Omitidos</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in resumen" :key="r.tabla">
                            <td>{{ r.tabla }}</td>
                            <td>{{ r.insertados }}</td>
                            <td>{{ r.actualizados }}</td>
                            <td>{{ r.omitidas || 0 }}</td>
                            <td>
                                <span v-if="r.omitida" class="badge badge-default">No estaba en el archivo</span>
                                <span v-else class="badge badge-success">OK</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Avisos: datos que se cargaron incompletos o se convirtieron -->
            <div v-if="avisos.length" class="card" style="margin-top:16px;">
                <h4 style="margin:0 0 8px;color:var(--color-warning, #b26a00);">
                    Revisar después de importar
                </h4>
                <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">
                    <li v-for="(a, i) in avisos" :key="i">{{ a }}</li>
                </ul>
            </div>
        </div>

        <ConfirmDialog ref="confirmRef" />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { dbBackupService } from '@/services/dbBackup';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import IconLib from '@/components/IconLib.vue';

const toast = useToast();

const exporting = ref(false);
async function exportar() {
    exporting.value = true;
    try {
        await dbBackupService.export();
        toast.success('Exportación generada.');
    } catch (err) {
        toast.error(extractError(err, 'No se pudo generar el export.'));
    } finally {
        exporting.value = false;
    }
}

const fileInput = ref(null);
const archivo = ref(null);
const importing = ref(false);
const resumen = ref([]);
const avisos = ref([]);

function onFile(ev) {
    archivo.value = ev.target.files?.[0] || null;
}

const confirmRef = ref(null);
async function confirmarImportar() {
    if (!archivo.value) return;
    const ok = await confirmRef.value.show({
        title: 'Importar base de datos',
        message: `Se cargará "${archivo.value.name}". Los registros existentes (por clave) se actualizarán y se insertarán los nuevos. ¿Continuar?`,
        confirmText: 'Importar',
        danger: true,
    });
    if (!ok) return;
    await importar();
}

async function importar() {
    importing.value = true;
    resumen.value = [];
    avisos.value = [];
    try {
        const res = await dbBackupService.import(archivo.value);
        resumen.value = res.data?.resumen || [];
        avisos.value = res.data?.avisos || [];
        toast.success('Importación completada.');
        archivo.value = null;
        if (fileInput.value) fileInput.value.value = '';
    } catch (err) {
        toast.error(extractError(err, 'No se pudo importar el archivo.'));
    } finally {
        importing.value = false;
    }
}
</script>

<style scoped>
.block-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 8px;
    font-size: 16px;
}
.block-text {
    color: var(--color-muted, #666);
    font-size: 14px;
    margin: 0 0 16px;
    max-width: 720px;
}
</style>
