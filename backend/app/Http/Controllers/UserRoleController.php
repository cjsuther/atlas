<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use App\Services\AccessScopeService;
use App\Support\SectorTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Administración de usuarios.
 *
 *   admin_sistema  : crea y modifica usuarios de cualquier rol y Gerencia de Área.
 *   admin_gerencia : crea y modifica operadores de su propia Gerencia de Área.
 */
class UserRoleController extends Controller
{
    public function __construct(
        protected AccessScopeService $scope,
        protected SectorTree $arbol,
    ) {}

    /**
     * GET /api/usuarios — listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $q = UserRole::query()->with('gerenciaArea:sector_id,nombre');

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

        if ($sectorId = $request->query('sector_id')) {
            $q->where('sector_id', (int) $sectorId);
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
        return response()->json(['data' => $user->load('gerenciaArea:sector_id,nombre')]);
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
            'sector_id'    => ['nullable', 'integer', 'exists:sector,sector_id'],
            'activo'       => ['sometimes', 'boolean'],
            'auth_source'  => ['required', 'in:local,ldap'],
            'password'     => ['exclude_if:auth_source,ldap', 'required', 'string', 'min:8', 'confirmed'],
        ], $this->mensajes())->validate();

        $sectorId = $this->resolverGerenciaArea($request, $data['rol'], $data['sector_id'] ?? null);
        if ($sectorId instanceof JsonResponse) {
            return $sectorId;
        }

        $user = new UserRole();
        $user->username     = $data['username'];
        $user->display_name = $data['display_name'] ?? $data['username'];
        $user->email        = $data['email'] ?? null;
        $user->rol          = $data['rol'];
        $user->sector_id    = $sectorId;
        $user->activo       = array_key_exists('activo', $data) ? (bool) $data['activo'] : true;
        $user->auth_source  = $data['auth_source'];
        $user->password     = $data['auth_source'] === 'local' ? $data['password'] : null; // se hashea por el cast 'hashed'
        $user->save();

        return response()->json(['data' => $user->load('gerenciaArea:sector_id,nombre')], 201);
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
            'sector_id'    => ['sometimes', 'nullable', 'integer', 'exists:sector,sector_id'],
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

        $rolFinal = $data['rol'] ?? $user->rol;
        $sectorId = $this->resolverGerenciaArea(
            $request,
            $rolFinal,
            array_key_exists('sector_id', $data) ? $data['sector_id'] : $user->sector_id,
        );
        if ($sectorId instanceof JsonResponse) {
            return $sectorId;
        }
        $data['sector_id'] = $sectorId;

        // Para usuarios LDAP, nombre y e-mail se sincronizan desde el directorio en cada login.
        if ($user->isLdap()) {
            unset($data['display_name'], $data['email']);
        }

        $user->fill($data);
        $user->save();

        return response()->json(['data' => $user->load('gerenciaArea:sector_id,nombre')]);
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

    /** El administrador de gerencia sólo ve y toca operadores de su Gerencia de Área. */
    private function limitarAGerencia($query, Request $request): void
    {
        $actual = $request->user();
        if ($actual instanceof UserRole && !$actual->isAdminSistema()) {
            // Los usuarios sin acceso no tienen gerencia todavía, así que sólo
            // los ve el administrador de sistema, que es quien los asigna.
            $query->where('sector_id', $actual->sector_id)
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
     * Determina la Gerencia de Área final del usuario y valida que quien
     * administra tenga permiso sobre ella.
     *
     * @return int|null|JsonResponse
     */
    private function resolverGerenciaArea(Request $request, string $rol, ?int $sectorId)
    {
        // Ni el administrador de sistema ni quien todavía no tiene permisos
        // están acotados a una Gerencia de Área.
        if ($rol === UserRole::ROL_ADMIN_SISTEMA || $rol === UserRole::ROL_SIN_ACCESO) {
            return null;
        }

        $actual = $request->user();
        if ($actual instanceof UserRole && !$actual->isAdminSistema()) {
            // Un administrador de gerencia sólo da de alta en la suya.
            return (int) $actual->sector_id;
        }

        if (!$sectorId) {
            return response()->json([
                'error'   => 'gerencia_requerida',
                'message' => 'Los roles de gerencia requieren indicar la Gerencia de Área del usuario.',
                'errors'  => ['sector_id' => ['Debe indicar la Gerencia de Área del usuario.']],
            ], 422);
        }

        // El alcance se define sobre una Gerencia de Área, no sobre un subsector.
        if (!$this->arbol->esRaiz($sectorId)) {
            return response()->json([
                'error'   => 'sector_no_raiz',
                'message' => 'El usuario debe asociarse a una Gerencia de Área, no a un subsector.',
                'errors'  => ['sector_id' => ['Debe elegir una Gerencia de Área (un sector sin dependencia).']],
            ], 422);
        }

        return (int) $sectorId;
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
            'sector_id.exists' => 'La Gerencia de Área indicada no existe.',
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
