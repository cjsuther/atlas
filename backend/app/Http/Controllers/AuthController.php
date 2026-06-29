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

        // Crear o actualizar el registro local de roles
        $user = UserRole::firstOrNew(['username' => $authPayload['username']]);
        $isNew = !$user->exists;
        if ($isNew) {
            $user->rol         = 'consulta';
            $user->activo      = 1;
            $user->auth_source = $authMethod === 'ldap' ? 'ldap' : 'local';
        }
        $user->display_name = $authPayload['display_name'] ?? $user->display_name ?? $authPayload['username'];
        $user->email        = $authPayload['email'] ?? $user->email;
        $user->last_login   = now();
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
            'new'      => $isNew,
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

    private function userPayload(UserRole $user): array
    {
        return [
            'id'           => $user->id,
            'username'     => $user->username,
            'display_name' => $user->display_name,
            'email'        => $user->email,
            'rol'          => $user->rol,
            'auth_source'  => $user->auth_source,
            'activo'       => (bool) $user->activo,
            'last_login'   => optional($user->last_login)->toIso8601String(),
        ];
    }
}
