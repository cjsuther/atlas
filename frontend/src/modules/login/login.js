import { api, auth } from '../../services/api.js';
import { router } from '../../services/router.js';
import { logoSvg } from '../../components/logo.js';
import { toast } from '../../services/toast.js';

export async function renderLogin() {
    const app = document.getElementById('app');
    app.innerHTML = `
        <div class="login-page">
            <form class="login-box" id="login-form" autocomplete="on">
                <div class="logo">
                    ${logoSvg(72)}
                    <h2>ATLAS</h2>
                </div>
                <p class="subtitle">
                    Administración y Trazabilidad de Licencias,<br/>Acuerdos y Servicios
                </p>

                <div id="login-error" class="error-msg" style="display:none;"></div>

                <div class="field">
                    <label for="username">Usuario institucional</label>
                    <input type="text" id="username" name="username" class="input" autocomplete="username" required autofocus />
                </div>
                <div class="field">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="input" autocomplete="current-password" required />
                </div>

                <button type="submit" class="btn btn-primary" id="btn-login" style="width:100%;margin-top:8px;">
                    Iniciar sesión
                </button>

                <p style="text-align:center;color:var(--color-muted);font-size:11px;margin-top:18px;">
                    Autenticación vía LDAP / Active Directory institucional
                </p>
            </form>
        </div>
    `;

    const form = document.getElementById('login-form');
    const errEl = document.getElementById('login-error');
    const btn = document.getElementById('btn-login');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errEl.style.display = 'none';
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        if (!username || !password) {
            errEl.textContent = 'Complete usuario y contraseña.';
            errEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="loader"></span> Validando...';

        try {
            const res = await api.post('/auth/login', { username, password });
            auth.setToken(res.token);
            auth.setUser(res.user);
            toast.success(`Bienvenido, ${res.user.display_name || res.user.username}.`);
            router.navigate('/dashboard');
        } catch (err) {
            const msg = (err.data && err.data.message) || err.message || 'Error de autenticación.';
            errEl.textContent = msg;
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Iniciar sesión';
        }
    });
}
