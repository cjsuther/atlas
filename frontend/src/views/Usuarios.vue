<template>
    <div>
        <h1 class="page-title">Usuarios y Roles</h1>
        <p class="page-subtitle">Gestión de usuarios y permisos del sistema</p>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Buscar</label>
                    <input v-model="state.search" type="text" class="input" placeholder="Usuario, nombre o e-mail..."
                           @input="onSearch" />
                </div>
                <div class="field">
                    <label>Rol</label>
                    <select v-model="state.rol" class="select" @change="load">
                        <option value="">Todos</option>
                        <option value="admin">admin</option>
                        <option value="operador">operador</option>
                        <option value="consulta">consulta</option>
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
                        <th>Activo</th>
                        <th>Último login</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in rows" :key="u.id">
                        <td>{{ u.username }}</td>
                        <td>{{ u.display_name || '—' }}</td>
                        <td>{{ u.email || '—' }}</td>
                        <td>
                            <select v-model="u.rol" class="select" style="max-width:140px;"
                                    @change="updateField(u, 'rol', u.rol)">
                                <option value="admin">admin</option>
                                <option value="operador">operador</option>
                                <option value="consulta">consulta</option>
                            </select>
                        </td>
                        <td>
                            <input type="checkbox" :checked="!!u.activo"
                                   @change="updateField(u, 'activo', $event.target.checked ? 1 : 0)" />
                        </td>
                        <td>{{ fmtDateTime(u.last_login) }}</td>
                    </tr>
                </tbody>
            </table>
            <BasePager :page="state.page" :per-page="state.per_page" :total="total" @change="goto" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { usuariosService } from '@/services/usuarios';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { debounce, fmtDateTime } from '@/composables/useFormat';
import BasePager from '@/components/BasePager.vue';

const toast = useToast();
const state = reactive({ search: '', rol: '', page: 1, per_page: 20 });
const rows = ref([]);
const total = ref(0);
const loading = ref(false);

const onSearch = debounce(() => { state.page = 1; load(); }, 300);

async function load() {
    loading.value = true;
    try {
        const params = { page: state.page, per_page: state.per_page };
        if (state.search) params.search = state.search;
        if (state.rol) params.rol = state.rol;
        const res = await usuariosService.list(params);
        rows.value = res.data || [];
        total.value = res.total || 0;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los usuarios.'));
    } finally {
        loading.value = false;
    }
}

function goto(p) { state.page = p; load(); }

async function updateField(u, field, value) {
    const prev = u[field];
    try {
        await usuariosService.update(u.username, { [field]: value });
        toast.success(`${u.username}: ${field} actualizado.`);
    } catch (err) {
        u[field] = prev;   // revertir UI
        toast.error(extractError(err, 'No se pudo actualizar el usuario.'));
    }
}

onMounted(load);
</script>
