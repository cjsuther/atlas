<template>
    <div class="login-page">
        <form class="login-box" @submit.prevent="submit" autocomplete="on">
            <div class="logo">
                <AtlasLogo :size="72" />
                <h2>ATLAS</h2>
            </div>
            <p class="subtitle">
                Administración y Trazabilidad de Licencias,<br />Acuerdos y Servicios
            </p>

            <div v-if="error" class="error-msg">{{ error }}</div>

            <div class="field">
                <label for="username">Usuario institucional</label>
                <input id="username" v-model="username" type="text" class="input" autocomplete="username"
                       required autofocus />
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" v-model="password" type="password" class="input"
                       autocomplete="current-password" required />
            </div>

            <button type="submit" class="btn btn-primary" :disabled="loading"
                    style="width:100%;margin-top:8px;">
                <span v-if="loading" class="loader" />
                <span>{{ loading ? 'Validando...' : 'Iniciar sesión' }}</span>
            </button>

            <p style="text-align:center;color:var(--color-muted);font-size:11px;margin-top:18px;">
                Autenticación vía LDAP / Active Directory institucional
            </p>
        </form>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { authService } from '@/services/auth';
import { extractError } from '@/services/http';
import AtlasLogo from '@/components/AtlasLogo.vue';

const router = useRouter();
const auth = useAuthStore();
const toast = useToast();

const username = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);

async function submit() {
    error.value = '';
    if (!username.value || !password.value) {
        error.value = 'Complete usuario y contraseña.';
        return;
    }
    loading.value = true;
    try {
        const res = await authService.login(username.value, password.value);
        auth.setSession(res.token, res.user);
        toast.success(`Bienvenido, ${res.user.display_name || res.user.username}.`);
        router.replace({ name: 'panel' });
    } catch (err) {
        error.value = extractError(err, 'Error de autenticación.');
    } finally {
        loading.value = false;
    }
}
</script>
