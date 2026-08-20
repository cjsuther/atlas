<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Usuarios y Roles</h1>
                <p class="page-subtitle">Administración de usuarios (locales y LDAP), roles y estado</p>
            </div>
            <div>
                <button class="btn btn-primary" @click="openNew">
                    <IconLib name="plus" /> Nuevo usuario
                </button>
            </div>
        </div>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Buscar</label>
                    <input v-model="state.search" type="text" class="input" placeholder="Usuario, nombre o e-mail..."
                           @input="onSearch" />
                </div>
                <div class="field">
                    <label>Rol</label>
                    <select v-model="state.rol" class="select" @change="reload">
                        <option value="">Todos</option>
                        <option v-for="r in rolesAsignables" :key="r" :value="r">{{ ROL_LABELS[r] }}</option>
                    </select>
                </div>
                <div v-if="auth.isAdminSistema && pendientes > 0" class="field aviso-pendientes">
                    <label>Pendientes de asignación</label>
                    <button type="button" class="btn btn-secondary" @click="verPendientes">
                        {{ pendientes }} usuario{{ pendientes === 1 ? '' : 's' }} sin acceso
                    </button>
                </div>
                <div v-if="auth.isAdminSistema" class="field">
                    <label>Gerencia de Área</label>
                    <select v-model="state.sector_id" class="select" @change="reload">
                        <option value="">Todas</option>
                        <option v-for="g in areas" :key="g.sector_id" :value="g.sector_id">{{ g.nombre }}</option>
                    </select>
                </div>
                <div class="field">
                    <label>Origen</label>
                    <select v-model="state.auth_source" class="select" @change="reload">
                        <option value="">Todos</option>
                        <option value="local">Local</option>
                        <option value="ldap">LDAP</option>
                    </select>
                </div>
                <div class="field">
                    <label>Estado</label>
                    <select v-model="state.activo" class="select" @change="reload">
                        <option value="">Todos</option>
                        <option value="1">Activos</option>
                        <option value="0">Inactivos</option>
                    </select>
                </div>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">Sin usuarios.</div>

        <div v-else class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>E-mail</th>
                        <th>Rol</th>
                        <th>Gerencia de Área</th>
                        <th>Origen</th>
                        <th>Activo</th>
                        <th>Último login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in rows" :key="u.id">
                        <td>{{ u.username }}</td>
                        <td>{{ u.display_name || '—' }}</td>
                        <td>{{ u.email || '—' }}</td>
                        <td><span class="badge" :class="rolBadge(u.rol)">{{ ROL_LABELS[u.rol] || u.rol }}</span></td>
                        <td>{{ u.gerencia_area?.nombre || '—' }}</td>
                        <td><span class="badge" :class="u.auth_source === 'local' ? 'badge-success' : 'badge-info'">{{ u.auth_source === 'local' ? 'Local' : 'LDAP' }}</span></td>
                        <td><span class="badge" :class="u.activo ? 'badge-success' : 'badge-default'">{{ u.activo ? 'Sí' : 'No' }}</span></td>
                        <td>{{ fmtDateTime(u.last_login) }}</td>
                        <td class="actions">
                            <button @click="openEdit(u)" title="Editar">
                                <IconLib name="edit" :size="14" />
                            </button>
                            <button v-if="u.auth_source === 'local'" @click="openReset(u)" title="Resetear contraseña" class="link-action">
                                Clave
                            </button>
                            <button class="danger" @click="remove(u)" title="Eliminar">
                                <IconLib name="trash" :size="14" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <BasePager :page="state.page" :per-page="state.per_page" :total="total" @change="goto" />
        </div>

        <!-- Alta / edición -->
        <BaseModal v-model="formOpen" :title="formTitle">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field">
                        <label>Usuario <span style="color:var(--color-danger);">*</span></label>
                        <input v-model="formData.username" type="text" class="input" maxlength="200"
                               :readonly="!!editing" :required="!editing" />
                        <div v-if="errors.username" class="error">{{ errors.username[0] }}</div>
                    </div>
                    <div class="field">
                        <label>Rol <span style="color:var(--color-danger);">*</span></label>
                        <select v-model="formData.rol" class="select" required>
                            <option v-for="r in rolesAsignables" :key="r" :value="r">{{ ROL_LABELS[r] }}</option>
                        </select>
                        <div v-if="errors.rol" class="error">{{ errors.rol[0] }}</div>
                    </div>
                    <div class="field" v-if="requiereGerencia">
                        <label>Gerencia de Área <span style="color:var(--color-danger);">*</span></label>
                        <select v-if="auth.isAdminSistema" v-model="formData.sector_id" class="select" required>
                            <option :value="null">—</option>
                            <option v-for="g in areas" :key="g.sector_id" :value="g.sector_id">{{ g.nombre }}</option>
                        </select>
                        <input v-else class="input" :value="auth.gerenciaArea || '—'" readonly />
                        <div class="hint">
                            {{ auth.isAdminSistema
                                ? 'El usuario ve los contratos de todos los subsectores de esta Gerencia de Área.'
                                : 'Sólo puede dar de alta usuarios en su propia Gerencia de Área.' }}
                        </div>
                        <div v-if="errors.sector_id" class="error">{{ errors.sector_id[0] }}</div>
                    </div>
                    <div class="field" v-if="!editing">
                        <label>Tipo de usuario <span style="color:var(--color-danger);">*</span></label>
                        <select v-model="formData.auth_source" class="select" required>
                            <option value="local">Base de datos (local)</option>
                            <option value="ldap">LDAP / Active Directory</option>
                        </select>
                        <div class="hint" v-if="formData.auth_source === 'ldap'">
                            Se autentica contra el directorio; no se define contraseña.
                        </div>
                        <div v-if="errors.auth_source" class="error">{{ errors.auth_source[0] }}</div>
                    </div>
                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="formData.display_name" type="text" class="input" maxlength="200"
                               :readonly="isLdapEdit" />
                        <div v-if="isLdapEdit" class="hint">Se sincroniza desde el directorio LDAP.</div>
                        <div v-if="errors.display_name" class="error">{{ errors.display_name[0] }}</div>
                    </div>
                    <div class="field">
                        <label>E-mail</label>
                        <input v-model="formData.email" type="email" class="input" maxlength="200"
                               :readonly="isLdapEdit" />
                        <div v-if="errors.email" class="error">{{ errors.email[0] }}</div>
                    </div>
                    <template v-if="!editing && formData.auth_source === 'local'">
                        <div class="field">
                            <label>Contraseña <span style="color:var(--color-danger);">*</span></label>
                            <input v-model="formData.password" type="password" class="input" autocomplete="new-password" required />
                            <div v-if="errors.password" class="error">{{ errors.password[0] }}</div>
                        </div>
                        <div class="field">
                            <label>Confirmar contraseña <span style="color:var(--color-danger);">*</span></label>
                            <input v-model="formData.password_confirmation" type="password" class="input" autocomplete="new-password" required />
                        </div>
                    </template>
                    <div class="field">
                        <label>Estado</label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                            <input v-model="formData.activo" type="checkbox" /> Activo
                        </label>
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

        <!-- Reset de contraseña -->
        <BaseModal v-model="resetOpen" :title="`Resetear contraseña — ${resetTarget?.username || ''}`">
            <form @submit.prevent="saveReset">
                <div class="form-grid">
                    <div class="field" style="grid-column:1 / -1;">
                        <label>Nueva contraseña <span style="color:var(--color-danger);">*</span></label>
                        <input v-model="resetData.password" type="password" class="input" autocomplete="new-password" required />
                        <div v-if="resetErrors.password" class="error">{{ resetErrors.password[0] }}</div>
                    </div>
                    <div class="field" style="grid-column:1 / -1;">
                        <label>Confirmar contraseña <span style="color:var(--color-danger);">*</span></label>
                        <input v-model="resetData.password_confirmation" type="password" class="input" autocomplete="new-password" required />
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-secondary" @click="resetOpen = false">Cancelar</button>
                <button type="button" class="btn btn-primary" @click="saveReset" :disabled="savingReset">
                    {{ savingReset ? 'Guardando…' : 'Cambiar contraseña' }}
                </button>
            </template>
        </BaseModal>

        <ConfirmDialog ref="confirmRef" />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { usuariosService } from '@/services/usuarios';
import { listAll } from '@/services/catalogos';
import { ROLES, ROL_LABELS, useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { debounce, fmtDateTime } from '@/composables/useFormat';
import BasePager from '@/components/BasePager.vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import IconLib from '@/components/IconLib.vue';

const toast = useToast();
const auth = useAuthStore();
const state = reactive({ search: '', rol: '', sector_id: '', auth_source: '', activo: '', page: 1, per_page: 20 });
const rows = ref([]);
const total = ref(0);
const sectores = ref([]);

/** Los usuarios se asocian a una Gerencia de Área: un sector sin dependencia. */
const areas = computed(() => sectores.value.filter(s => s.dependencia_id === null));
const loading = ref(false);

const onSearch = debounce(() => { state.page = 1; load(); }, 300);

/**
 * El administrador de sistema asigna cualquier rol; el de gerencia sólo puede
 * dar de alta operadores, y siempre en su propia gerencia.
 */
const rolesAsignables = computed(() => auth.isAdminSistema
    ? [ROLES.ADMIN_SISTEMA, ROLES.ADMIN_GERENCIA, ROLES.OPERADOR_GERENCIA, ROLES.SIN_ACCESO]
    : [ROLES.OPERADOR_GERENCIA]);

function rolBadge(rol) {
    if (rol === ROLES.ADMIN_SISTEMA) return 'badge-warning';
    if (rol === ROLES.ADMIN_GERENCIA) return 'badge-info';
    if (rol === ROLES.SIN_ACCESO) return 'badge-danger';
    return 'badge-default';
}

async function load() {
    loading.value = true;
    try {
        const params = { page: state.page, per_page: state.per_page };
        if (state.search) params.search = state.search;
        if (state.rol) params.rol = state.rol;
        if (state.sector_id) params.sector_id = state.sector_id;
        if (state.auth_source) params.auth_source = state.auth_source;
        if (state.activo !== '') params.activo = state.activo;
        const res = await usuariosService.list(params);
        rows.value = res.data || [];
        total.value = res.total || 0;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los usuarios.'));
    } finally {
        loading.value = false;
    }
}

/**
 * Usuarios que entraron por LDAP y todavía esperan que se les asigne rol y
 * Gerencia de Área. Se cuentan aparte para que no pasen inadvertidos.
 */
const pendientes = ref(0);

async function contarPendientes() {
    if (!auth.isAdminSistema) return;
    try {
        const res = await usuariosService.list({ rol: ROLES.SIN_ACCESO, per_page: 1 });
        pendientes.value = res.total || 0;
    } catch { /* no-op */ }
}

function verPendientes() {
    state.rol = ROLES.SIN_ACCESO;
    reload();
}

function reload() { state.page = 1; load(); }
function goto(p) { state.page = p; load(); }

// ---- Alta / edición ----
const formOpen = ref(false);
const editing = ref(null);
const formData = reactive({});
const errors = ref({});
const saving = ref(false);

const formTitle = computed(() => editing.value ? `Editar — ${editing.value.username}` : 'Nuevo usuario');
const isLdapEdit = computed(() => !!editing.value && editing.value.auth_source === 'ldap');
/** Ni el administrador de sistema ni quien no tiene permisos llevan gerencia. */
const requiereGerencia = computed(() =>
    formData.rol !== ROLES.ADMIN_SISTEMA && formData.rol !== ROLES.SIN_ACCESO);

function resetForm(values) {
    Object.keys(formData).forEach(k => delete formData[k]);
    Object.assign(formData, values);
    errors.value = {};
}

function openNew() {
    editing.value = null;
    resetForm({
        username: '', display_name: '', email: '',
        rol: ROLES.OPERADOR_GERENCIA,
        sector_id: auth.isAdminSistema ? null : auth.sectorId,
        auth_source: 'local', activo: true,
        password: '', password_confirmation: '',
    });
    formOpen.value = true;
}

function openEdit(u) {
    editing.value = u;
    resetForm({
        username: u.username,
        display_name: u.display_name || '',
        email: u.email || '',
        rol: u.rol,
        sector_id: u.sector_id ?? null,
        activo: !!u.activo,
    });
    formOpen.value = true;
}

async function save() {
    errors.value = {};
    saving.value = true;
    try {
        if (editing.value) {
            const payload = { rol: formData.rol, activo: formData.activo };
            if (requiereGerencia.value) payload.sector_id = formData.sector_id;
            if (!isLdapEdit.value) {
                payload.display_name = formData.display_name;
                payload.email = formData.email;
            }
            await usuariosService.update(editing.value.username, payload);
            toast.success('Usuario actualizado.');
        } else {
            const payload = {
                username: formData.username,
                display_name: formData.display_name,
                email: formData.email,
                rol: formData.rol,
                auth_source: formData.auth_source,
                activo: formData.activo,
            };
            if (requiereGerencia.value) payload.sector_id = formData.sector_id;
            if (formData.auth_source === 'local') {
                payload.password = formData.password;
                payload.password_confirmation = formData.password_confirmation;
            }
            await usuariosService.create(payload);
            toast.success('Usuario creado.');
        }
        formOpen.value = false;
        load();
        contarPendientes();
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            errors.value = err.response.data.errors;
            toast.error('Hay errores de validación.');
        } else {
            toast.error(extractError(err, 'No se pudo guardar el usuario.'));
        }
    } finally {
        saving.value = false;
    }
}

// ---- Reset de contraseña ----
const resetOpen = ref(false);
const resetTarget = ref(null);
const resetData = reactive({ password: '', password_confirmation: '' });
const resetErrors = ref({});
const savingReset = ref(false);

function openReset(u) {
    resetTarget.value = u;
    resetData.password = '';
    resetData.password_confirmation = '';
    resetErrors.value = {};
    resetOpen.value = true;
}

async function saveReset() {
    resetErrors.value = {};
    savingReset.value = true;
    try {
        await usuariosService.resetPassword(resetTarget.value.username, {
            password: resetData.password,
            password_confirmation: resetData.password_confirmation,
        });
        toast.success('Contraseña actualizada.');
        resetOpen.value = false;
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            resetErrors.value = err.response.data.errors;
            toast.error('Hay errores de validación.');
        } else {
            toast.error(extractError(err, 'No se pudo cambiar la contraseña.'));
        }
    } finally {
        savingReset.value = false;
    }
}

// ---- Eliminar ----
const confirmRef = ref(null);
async function remove(u) {
    const ok = await confirmRef.value.show({
        title: 'Eliminar usuario',
        message: `¿Eliminar al usuario "${u.username}"?`,
        confirmText: 'Eliminar',
        danger: true,
    });
    if (!ok) return;
    try {
        await usuariosService.remove(u.username);
        toast.success('Usuario eliminado.');
        load();
    } catch (err) {
        toast.error(extractError(err, 'No se pudo eliminar el usuario.'));
    }
}

onMounted(async () => {
    if (auth.isAdminSistema) {
        try { sectores.value = await listAll('sectores'); } catch { /* no-op */ }
        contarPendientes();
    }
    load();
});
</script>

<style scoped>
.hint { font-size: 12px; color: var(--color-muted, #888); margin-top: 4px; }
.link-action {
    font-size: 12px;
    padding: 2px 6px;
}
</style>
