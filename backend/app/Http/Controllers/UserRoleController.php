<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserRoleController extends Controller
{
    /**
     * GET /api/usuarios — listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $q = UserRole::query();

        if ($search = $request->query('search')) {
            $term = '%' . $search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('username', 'like', $term)
                  ->orWhere('display_name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if ($rol = $request->query('rol')) {
            $q->where('rol', $rol);
        }

        if (($activo = $request->query('activo')) !== null && $activo !== '') {
            $q->where('activo', filter_var($activo, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if ($source = $request->query('auth_source')) {
            $q->where('auth_source', $source);
        }

        $orderBy  = in_array($request->query('order_by'), ['username', 'display_name', 'email', 'rol', 'last_login', 'activo'], true)
            ? $request->query('order_by') : 'username';
        $orderDir = strtolower($request->query('order_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $q->orderBy($orderBy, $orderDir);

        $perPage = max(1, min((int) $request->query('per_page', 20), 200));
        return response()->json($q->paginate($perPage));
    }

    /**
     * GET /api/usuarios/{username} — detalle de un usuario.
     */
    public function show(string $username): JsonResponse
    {
        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return $this->notFound();
        }
        return response()->json(['data' => $user]);
    }

    /**
     * POST /api/usuarios — crear un usuario local (con contraseña en BD) o LDAP.
     * El tipo se define con auth_source: 'local' requiere contraseña; 'ldap' se
     * autentica contra el directorio (sin contraseña en BD).
     */
    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'username'     => ['required', 'string', 'max:200', Rule::unique('user_roles', 'username')],
            'display_name' => ['nullable', 'string', 'max:200'],
            'email'        => ['nullable', 'email', 'max:200'],
            'rol'          => ['required', 'in:admin,operador,consulta'],
            'activo'       => ['sometimes', 'boolean'],
            'auth_source'  => ['required', 'in:local,ldap'],
            'password'     => ['exclude_if:auth_source,ldap', 'required', 'string', 'min:8', 'confirmed'],
        ])->validate();

        $user = new UserRole();
        $user->username     = $data['username'];
        $user->display_name = $data['display_name'] ?? $data['username'];
        $user->email        = $data['email'] ?? null;
        $user->rol          = $data['rol'];
        $user->activo       = array_key_exists('activo', $data) ? (bool) $data['activo'] : true;
        $user->auth_source  = $data['auth_source'];
        $user->password     = $data['auth_source'] === 'local' ? $data['password'] : null; // se hashea por el cast 'hashed'
        $user->save();

        return response()->json(['data' => $user], 201);
    }

    /**
     * PUT /api/usuarios/{username} — editar datos, rol y/o estado.
     * El username es inmutable. La contraseña se cambia con el endpoint dedicado.
     */
    public function update(Request $request, string $username): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email'        => ['sometimes', 'nullable', 'email', 'max:200'],
            'rol'          => ['sometimes', 'in:admin,operador,consulta'],
            'activo'       => ['sometimes', 'boolean'],
        ])->validate();

        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return $this->notFound();
        }

        // Cambios que podrían dejar al sistema sin administradores activos.
        $degradaRol  = array_key_exists('rol', $data) && $data['rol'] !== 'admin';
        $desactiva   = array_key_exists('activo', $data) && !$data['activo'];
        if ($user->isAdmin() && ($degradaRol || $desactiva) && $this->isLastActiveAdmin($username)) {
            return response()->json([
                'error'   => 'last_admin',
                'message' => 'No se puede degradar o desactivar al último administrador activo.',
            ], 409);
        }

        // Para usuarios LDAP, nombre y e-mail se sincronizan desde el directorio en cada login.
        if ($user->isLdap()) {
            unset($data['display_name'], $data['email']);
        }

        $user->fill($data);
        $user->save();

        return response()->json(['data' => $user]);
    }

    /**
     * POST /api/usuarios/{username}/password — resetear la contraseña local.
     */
    public function resetPassword(Request $request, string $username): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ])->validate();

        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return $this->notFound();
        }

        if (!$user->isLocal()) {
            return response()->json([
                'error'   => 'not_local',
                'message' => 'Solo se puede asignar contraseña a usuarios locales. Los usuarios LDAP se autentican contra el directorio.',
            ], 422);
        }

        $user->password = $data['password']; // se hashea por el cast 'hashed'
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    /**
     * DELETE /api/usuarios/{username} — eliminar un usuario.
     */
    public function destroy(Request $request, string $username): JsonResponse
    {
        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return $this->notFound();
        }

        $actual = $request->user();
        if ($actual && $actual->username === $user->username) {
            return response()->json([
                'error'   => 'self_delete',
                'message' => 'No puede eliminar su propio usuario.',
            ], 403);
        }

        if ($user->isAdmin() && $this->isLastActiveAdmin($username)) {
            return response()->json([
                'error'   => 'last_admin',
                'message' => 'No se puede eliminar al último administrador activo.',
            ], 409);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado.',
        ]);
    }

    /**
     * Indica si, excluyendo al usuario dado, no quedan otros administradores activos.
     */
    private function isLastActiveAdmin(string $username): bool
    {
        return UserRole::where('rol', 'admin')
            ->where('activo', 1)
            ->where('username', '!=', $username)
            ->count() === 0;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => 'Usuario no encontrado.',
        ], 404);
    }
}
