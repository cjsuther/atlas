<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use App\Services\LdapAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(protected LdapAuthService $ldap)
    {
    }

    /**
     * POST /api/auth/login
     *
     * Body: { username, password }
     * Devuelve: { token, user: { username, display_name, email, rol } }
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string'],
        ]);

        $localEnabled = filter_var(env('AUTH_LOCAL_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $ldapEnabled  = filter_var(env('AUTH_LDAP_ENABLED', true), FILTER_VALIDATE_BOOLEAN);

        $authMethod = null;
        $authPayload = null;

        // 1) Intento de autenticación local (si está habilitada)
        if ($localEnabled) {
            $localUser = UserRole::where('username', $data['username'])->first();
            if ($localUser && $localUser->hasLocalPassword()
                && Hash::check($data['password'], $localUser->password)) {
                $authMethod  = 'local';
                $authPayload = [
                    'username'     => $localUser->username,
                    'display_name' => $localUser->display_name,
                    'email'        => $localUser->email,
                ];
            }
        }

        // 2) Fallback a LDAP (si está habilitado y la auth local no autorizó)
        if (!$authMethod && $ldapEnabled) {
            $ldapUser = $this->ldap->authenticate($data['username'], $data['password']);
            if ($ldapUser) {
                $authMethod  = 'ldap';
                $authPayload = $ldapUser;
            }
        }

        if (!$authMethod) {
            return response()->json([
                'error'   => 'invalid_credentials',
                'message' => 'Usuario o contraseña inválidos.',
            ], 401);
        }

        $user = UserRole::where('username', $authPayload['username'])->first();

        // Quien llega por LDAP y todavía no está en el sistema se da de alta sin
        // acceso: el directorio confirma quién es, pero qué puede ver lo decide
        // un administrador asignándole rol y Gerencia de Área.
        if (!$user && $authMethod === 'ldap') {
            $user = new UserRole();
            $user->username     = $authPayload['username'];
            $user->display_name = $authPayload['display_name'] ?? $authPayload['username'];
            $user->email        = $authPayload['email'] ?? null;
            $user->auth_source  = 'ldap';
            $user->rol          = UserRole::ROL_SIN_ACCESO;
            $user->sector_id    = null;
            $user->activo       = true;
            $user->save();

            Log::info('ATLAS AUTH: alta automática de usuario LDAP sin acceso', [
                'username' => $user->username,
            ]);
        }

        // Los usuarios locales no se autocrean: no hay directorio contra el cual
        // validarlos, así que los da de alta un administrador.
        if (!$user) {
            Log::warning('ATLAS AUTH: login rechazado, usuario no registrado', [
                'username' => $authPayload['username'],
                'method'   => $authMethod,
            ]);
            return response()->json([
                'error'   => 'user_not_provisioned',
                'message' => 'Usuario no registrado en el sistema. Contacte al administrador.',
            ], 403);
        }

        // El método de autenticación debe coincidir con el tipo declarado del usuario.
        if ($authMethod === 'ldap' && !$user->isLdap()) {
            Log::warning('ATLAS AUTH: login rechazado, tipo de usuario no coincide', [
                'username'    => $user->username,
                'auth_source' => $user->auth_source,
                'method'      => $authMethod,
            ]);
            return response()->json([
                'error'   => 'auth_source_mismatch',
                'message' => 'Este usuario no está habilitado para autenticación LDAP.',
            ], 403);
        }

        // Para usuarios LDAP, sincronizar nombre y e-mail desde el directorio en cada login.
        if ($authMethod === 'ldap') {
            $user->display_name = $authPayload['display_name'] ?? $user->display_name;
            $user->email        = $authPayload['email'] ?? $user->email;
        }
        $user->last_login = now();
        $user->save();

        if (!$user->activo) {
            return response()->json([
                'error'   => 'inactive_user',
                'message' => 'Su usuario fue desactivado. Contacte al administrador.',
            ], 403);
        }

        // Revocar tokens previos del usuario y emitir uno nuevo
        $user->tokens()->delete();
        $token = $user->createToken('atlas-' . now()->timestamp)->plainTextToken;

        Log::info('ATLAS AUTH: login exitoso', [
            'username' => $user->username,
            'rol'      => $user->rol,
            'method'   => $authMethod,
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * PUT /api/auth/preferencias
     *
     * Configuración de visualización del usuario. Por ahora, con qué
     * agrupación quiere ver los saldos del panel: por Gerencia de Área, por
     * Subsector o por Contrato.
     */
    public function preferencias(Request $request): JsonResponse
    {
        $data = $request->validate([
            'saldos_agrupacion' => ['required', 'in:' . implode(',', UserRole::AGRUPACIONES_SALDO)],
        ], [
            'saldos_agrupacion.in' => 'La agrupación de saldos debe ser por Gerencia de Área, Subsector o Contrato.',
        ]);

        $user = $request->user();
        $user->saldos_agrupacion = $data['saldos_agrupacion'];
        $user->save();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    private function userPayload(UserRole $user): array
    {
        $user->loadMissing('gerenciaArea');

        return [
            'id'                => $user->id,
            'username'          => $user->username,
            'display_name'      => $user->display_name,
            'email'             => $user->email,
            'rol'               => $user->rol,
            'auth_source'       => $user->auth_source,
            'sector_id'         => $user->sector_id,
            'gerencia_area'     => optional($user->gerenciaArea)->nombre,
            'saldos_agrupacion' => $user->saldos_agrupacion,
            'activo'            => (bool) $user->activo,
            'last_login'        => optional($user->last_login)->toIso8601String(),
        ];
    }
}
