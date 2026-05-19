<template>
    <div v-if="!def" class="empty-state">Catálogo no encontrado.</div>

    <div v-else>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">{{ def.title }}</h1>
                <p class="page-subtitle">Listado y gestión del catálogo</p>
            </div>
            <div>
                <button v-if="auth.isAdmin" class="btn btn-primary" @click="openNew">
                    <IconLib name="plus" /> Nuevo
                </button>
            </div>
        </div>

        <div class="toolbar">
            <div class="search-box">
                <input v-model="state.search" type="text" class="input" placeholder="Buscar..." @input="onSearch" />
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">Sin registros.</div>
        <div v-else class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th v-for="c in def.columns" :key="c.key">{{ c.label }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r[def.keyField]">
                        <td v-for="c in def.columns" :key="c.key">
                            {{ c.render ? c.render(r) : (r[c.key] ?? '—') }}
                        </td>
                        <td class="actions">
                            <button v-if="auth.isAdmin" @click="openEdit(r)" title="Editar">
                                <IconLib name="edit" :size="14" />
                            </button>
                            <button v-if="auth.isAdmin" class="danger" @click="remove(r)" title="Eliminar">
                                <IconLib name="trash" :size="14" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <BasePager :page="state.page" :per-page="state.per_page" :total="total" @change="goto" />
        </div>

        <BaseModal v-model="formOpen" :title="formTitle">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div v-for="f in def.formFields" :key="f.name" class="field"
                         :class="{ full: f.full }"
                         :style="def.formFields.length === 1 ? 'grid-column: 1 / -1;' : ''">
                        <label>{{ f.label }} <span v-if="f.required" style="color:var(--color-danger);">*</span></label>

                        <input v-if="f.type === 'text' || f.type === 'email'"
                               v-model="formData[f.name]" :type="f.type" class="input"
                               :required="f.required && !(editing && f.onlyOnCreate)"
                               :maxlength="f.max"
                               :readonly="editing && f.onlyOnCreate" />

                        <input v-else-if="f.type === 'number'"
                               v-model="formData[f.name]" type="number" class="input"
                               :required="f.required && !(editing && f.onlyOnCreate)"
                               :readonly="editing && f.onlyOnCreate" />

                        <textarea v-else-if="f.type === 'textarea'"
                                  v-model="formData[f.name]" class="textarea" :required="f.required" />

                        <select v-else-if="f.type === 'select'"
                                v-model="formData[f.name]" class="select" :required="f.required">
                            <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>

                        <select v-else-if="f.type === 'select-async'"
                                v-model="formData[f.name]" class="select">
                            <option :value="null">— Sin selección —</option>
                            <option v-for="o in (asyncOptions[f.name] || [])" :key="o[f.valueKey]" :value="o[f.valueKey]">
                                {{ o[f.labelKey] }}
                            </option>
                        </select>

                        <div v-if="errors[f.name]" class="error">{{ errors[f.name][0] }}</div>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary" @click="formOpen = false">Cancelar</button>
                <button type="button" class="btn btn-primary" @click="save" :disabled="saving">
                    {{ saving ? 'Guardando…' : 'Guardar' }}
                </button>
            </template>
        </BaseModal>

        <ConfirmDialog ref="confirmRef" />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { catalogoService, listAll } from '@/services/catalogos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { debounce } from '@/composables/useFormat';
import { ENTITY_DEFS } from './definitions';
import BasePager from '@/components/BasePager.vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import IconLib from '@/components/IconLib.vue';

const route = useRoute();
const auth = useAuthStore();
const toast = useToast();

const def = computed(() => ENTITY_DEFS[route.params.slug] || null);
const service = computed(() => def.value ? catalogoService(def.value.endpoint) : null);

const state = reactive({ search: '', page: 1, per_page: 20 });
const rows = ref([]);
const total = ref(0);
const loading = ref(false);

const formOpen = ref(false);
const editing = ref(null);
const formData = reactive({});
const errors = ref({});
const saving = ref(false);
const asyncOptions = reactive({});

const formTitle = computed(() => editing.value
    ? `Editar — ${def.value?.title}`
    : `Nuevo — ${def.value?.title}`);

const onSearch = debounce(() => { state.page = 1; load(); }, 300);

watch(() => route.params.slug, () => {
    state.search = ''; state.page = 1;
    rows.value = []; total.value = 0;
    if (def.value) load();
});

async function load() {
    if (!service.value) return;
    loading.value = true;
    try {
        const res = await service.value.list({
            page: state.page, per_page: state.per_page,
            ...(state.search ? { search: state.search } : {}),
        });
        rows.value = res.data || [];
        total.value = res.total || 0;
    } catch (err) {
        toast.error(extractError(err, 'No se pudo cargar el listado.'));
    } finally {
        loading.value = false;
    }
}

function goto(p) { state.page = p; load(); }

async function loadAsyncOptions() {
    asyncOptions && Object.keys(asyncOptions).forEach(k => delete asyncOptions[k]);
    for (const f of def.value.formFields) {
        if (f.type === 'select-async') {
            try {
                asyncOptions[f.name] = await listAll(f.endpoint);
            } catch { asyncOptions[f.name] = []; }
        }
    }
}

async function openNew() {
    editing.value = null;
    errors.value = {};
    for (const k of Object.keys(formData)) delete formData[k];
    for (const f of def.value.formFields) {
        formData[f.name] = f.type === 'select-async' ? null : '';
    }
    await loadAsyncOptions();
    formOpen.value = true;
}

async function openEdit(r) {
    editing.value = r;
    errors.value = {};
    for (const k of Object.keys(formData)) delete formData[k];
    for (const f of def.value.formFields) {
        formData[f.name] = r[f.name] ?? (f.type === 'select-async' ? null : '');
    }
    await loadAsyncOptions();
    formOpen.value = true;
}

async function save() {
    errors.value = {};
    saving.value = true;
    try {
        const payload = { ...formData };
        // No mandar el legajo en update si onlyOnCreate
        if (editing.value) {
            for (const f of def.value.formFields) {
                if (f.onlyOnCreate) delete payload[f.name];
            }
            await service.value.update(editing.value[def.value.keyField], payload);
            toast.success('Registro actualizado.');
        } else {
            await service.value.create(payload);
            toast.success('Registro creado.');
        }
        formOpen.value = false;
        load();
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            errors.value = err.response.data.errors;
            toast.error('Hay errores de validación.');
        } else {
            toast.error(extractError(err, 'No se pudo guardar.'));
        }
    } finally {
        saving.value = false;
    }
}

const confirmRef = ref(null);
async function remove(r) {
    const ok = await confirmRef.value.show({
        title: 'Eliminar',
        message: `¿Eliminar el registro #${r[def.value.keyField]}?`,
        confirmText: 'Eliminar',
        danger: true,
    });
    if (!ok) return;
    try {
        await service.value.remove(r[def.value.keyField]);
        toast.success('Eliminado.');
        load();
    } catch (err) {
        if (err?.response?.status === 409) {
            toast.error(extractError(err, 'No se puede eliminar (hay dependencias).'));
        } else {
            toast.error(extractError(err, 'No se pudo eliminar.'));
        }
    }
}

onMounted(() => { if (def.value) load(); });
</script>
