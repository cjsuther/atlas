<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use App\Services\AccessScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Administración de usuarios.
 *
 *   admin_sistema  : crea y modifica usuarios de cualquier rol y gerencia.
 *   admin_gerencia : crea y modifica operadores de su propia gerencia.
 */
class UserRoleController extends Controller
{
    public function __construct(protected AccessScopeService $scope) {}

    /**
     * GET /api/usuarios — listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $q = UserRole::query()->with('gerencia:id,gerencia_area_id,sigla,nombre');

        $this->limitarAGerencia($q, $request);

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

        if ($gerenciaId = $request->query('gerencia_id')) {
            $q->where('gerencia_id', (int) $gerenciaId);
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
    public function show(Request $request, string $username): JsonResponse
    {
        $user = $this->buscarEnAlcance($request, $username);
        if (!$user) {
            return $this->notFound();
        }
        return response()->json(['data' => $user->load('gerencia:id,gerencia_area_id,sigla,nombre')]);
    }

    /**
     * POST /api/usuarios — crear un usuario local (con contraseña en BD) o LDAP.
     * El tipo se define con auth_source: 'local' requiere contraseña; 'ldap' se
     * autentica contra el directorio (sin contraseña en BD).
     */
    public function store(Request $request): JsonResponse
    {
        $rolesAsignables = $this->scope->rolesAsignables($request->user());

        $data = Validator::make($request->all(), [
            'username'     => ['required', 'string', 'max:200', Rule::unique('user_roles', 'username')],
            'display_name' => ['nullable', 'string', 'max:200'],
            'email'        => ['nullable', 'email', 'max:200'],
            'rol'          => ['required', Rule::in($rolesAsignables)],
            'gerencia_id'  => ['nullable', 'integer', 'exists:gerencias,id'],
            'activo'       => ['sometimes', 'boolean'],
            'auth_source'  => ['required', 'in:local,ldap'],
            'password'     => ['exclude_if:auth_source,ldap', 'required', 'string', 'min:8', 'confirmed'],
        ], $this->mensajes())->validate();

        $gerenciaId = $this->resolverGerencia($request, $data['rol'], $data['gerencia_id'] ?? null);
        if ($gerenciaId instanceof JsonResponse) {
            return $gerenciaId;
        }

        $user = new UserRole();
        $user->username     = $data['username'];
        $user->display_name = $data['display_name'] ?? $data['username'];
        $user->email        = $data['email'] ?? null;
        $user->rol          = $data['rol'];
        $user->gerencia_id  = $gerenciaId;
        $user->activo       = array_key_exists('activo', $data) ? (bool) $data['activo'] : true;
        $user->auth_source  = $data['auth_source'];
        $user->password     = $data['auth_source'] === 'local' ? $data['password'] : null; // se hashea por el cast 'hashed'
        $user->save();

        return response()->json(['data' => $user->load('gerencia:id,gerencia_area_id,sigla,nombre')], 201);
    }

    /**
     * PUT /api/usuarios/{username} — editar datos, rol, gerencia y/o estado.
     * El username es inmutable. La contraseña se cambia con el endpoint dedicado.
     */
    public function update(Request $request, string $username): JsonResponse
    {
        $rolesAsignables = $this->scope->rolesAsignables($request->user());

        $data = Validator::make($request->all(), [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email'        => ['sometimes', 'nullable', 'email', 'max:200'],
            'rol'          => ['sometimes', Rule::in($rolesAsignables)],
            'gerencia_id'  => ['sometimes', 'nullable', 'integer', 'exists:gerencias,id'],
            'activo'       => ['sometimes', 'boolean'],
        ], $this->mensajes())->validate();

        $user = $this->buscarEnAlcance($request, $username);
        if (!$user) {
            return $this->notFound();
        }

        // Cambios que podrían dejar al sistema sin administradores de sistema activos.
        $degradaRol = array_key_exists('rol', $data) && $data['rol'] !== UserRole::ROL_ADMIN_SISTEMA;
        $desactiva  = array_key_exists('activo', $data) && !$data['activo'];
        if ($user->isAdminSistema() && ($degradaRol || $desactiva) && $this->isLastActiveAdmin($username)) {
            return response()->json([
                'error'   => 'last_admin',
                'message' => 'No se puede degradar o desactivar al último administrador de sistema activo.',
            ], 409);
        }

        $rolFinal   = $data['rol'] ?? $user->rol;
        $gerenciaId = $this->resolverGerencia(
            $request,
            $rolFinal,
            array_key_exists('gerencia_id', $data) ? $data['gerencia_id'] : $user->gerencia_id,
        );
        if ($gerenciaId instanceof JsonResponse) {
            return $gerenciaId;
        }
        $data['gerencia_id'] = $gerenciaId;

        // Para usuarios LDAP, nombre y e-mail se sincronizan desde el directorio en cada login.
        if ($user->isLdap()) {
            unset($data['display_name'], $data['email']);
        }

        $user->fill($data);
        $user->save();

        return response()->json(['data' => $user->load('gerencia:id,gerencia_area_id,sigla,nombre')]);
    }

    /**
     * POST /api/usuarios/{username}/password — resetear la contraseña local.
     */
    public function resetPassword(Request $request, string $username): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ])->validate();

        $user = $this->buscarEnAlcance($request, $username);
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
        $user = $this->buscarEnAlcance($request, $username);
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

        if ($user->isAdminSistema() && $this->isLastActiveAdmin($username)) {
            return response()->json([
                'error'   => 'last_admin',
                'message' => 'No se puede eliminar al último administrador de sistema activo.',
            ], 409);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado.',
        ]);
    }

    // ------------------------------------------------------------------
    // Alcance
    // ------------------------------------------------------------------

    /** El administrador de gerencia sólo ve y toca usuarios de su gerencia. */
    private function limitarAGerencia($query, Request $request): void
    {
        $actual = $request->user();
        if ($actual instanceof UserRole && !$actual->isAdminSistema()) {
            $query->where('gerencia_id', $actual->gerencia_id)
                  ->where('rol', UserRole::ROL_OPERADOR_GERENCIA);
        }
    }

    private function buscarEnAlcance(Request $request, string $username): ?UserRole
    {
        $q = UserRole::where('username', $username);
        $this->limitarAGerencia($q, $request);
        return $q->first();
    }

    /**
     * Determina la gerencia final del usuario y valida que quien administra
     * tenga permiso sobre ella.
     *
     * @return int|null|JsonResponse
     */
    private function resolverGerencia(Request $request, string $rol, ?int $gerenciaId)
    {
        // El administrador de sistema no está acotado a ninguna gerencia.
        if ($rol === UserRole::ROL_ADMIN_SISTEMA) {
            return null;
        }

        $actual = $request->user();
        if ($actual instanceof UserRole && !$actual->isAdminSistema()) {
            // Un administrador de gerencia sólo da de alta en la suya.
            return (int) $actual->gerencia_id;
        }

        if (!$gerenciaId) {
            return response()->json([
                'error'   => 'gerencia_requerida',
                'message' => 'Los roles de gerencia requieren indicar a qué gerencia pertenece el usuario.',
                'errors'  => ['gerencia_id' => ['Debe indicar la gerencia del usuario.']],
            ], 422);
        }

        return (int) $gerenciaId;
    }

    /**
     * Indica si, excluyendo al usuario dado, no quedan otros administradores
     * de sistema activos.
     */
    private function isLastActiveAdmin(string $username): bool
    {
        return UserRole::where('rol', UserRole::ROL_ADMIN_SISTEMA)
            ->where('activo', 1)
            ->where('username', '!=', $username)
            ->count() === 0;
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'rol.in'          => 'No tiene permisos para asignar ese rol.',
            'gerencia_id.exists' => 'La gerencia indicada no existe.',
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => 'Usuario no encontrado.',
        ], 404);
    }
}
