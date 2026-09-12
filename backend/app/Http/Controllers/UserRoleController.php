<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use App\Models\UsuarioPermiso;
use App\Support\SectorTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Administración de usuarios y de sus permisos sobre el árbol.
 *
 * Es exclusiva del administrador del sistema: no hay roles intermedios que
 * deleguen el alta de usuarios.
 *
 * A cada usuario se le asignan nodos del árbol —o la raíz, que es toda la
 * organización— con nivel de lectura o de escritura. El permiso se hereda hacia
 * abajo y la escritura incluye la lectura, así que no hace falta repetirlo en
 * cada nivel.
 */
class UserRoleController extends Controller
{
    public function __construct(protected SectorTree $arbol) {}

    /**
     * GET /api/usuarios — listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $q = UserRole::query()->with('permisos.sector:sector_id,nombre');

        if ($search = $request->query('search')) {
            $term = '%' . $search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('username', 'like', $term)
                  ->orWhere('display_name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if (($admin = $request->query('es_admin')) !== null && $admin !== '') {
            $q->where('es_admin', filter_var($admin, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        // Filtro por rama: los usuarios con permiso sobre ese nodo o sobre
        // cualquiera de sus ancestros, porque el permiso se hereda.
        if ($sectorId = $request->query('sector_id')) {
            $ancestros = $this->ancestrosDe((int) $sectorId);
            $q->whereHas('permisos', function ($w) use ($ancestros) {
                $w->whereIn('sector_id', $ancestros)->orWhereNull('sector_id');
            });
        }

        if (($activo = $request->query('activo')) !== null && $activo !== '') {
            $q->where('activo', filter_var($activo, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if ($source = $request->query('auth_source')) {
            $q->where('auth_source', $source);
        }

        $orderBy  = in_array($request->query('order_by'),
            ['username', 'display_name', 'email', 'es_admin', 'last_login', 'activo'], true)
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
        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return $this->notFound();
        }
        return response()->json(['data' => $user->load('permisos.sector:sector_id,nombre')]);
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
            'es_admin'     => ['sometimes', 'boolean'],
            'activo'       => ['sometimes', 'boolean'],
            'auth_source'  => ['required', 'in:local,ldap'],
            'password'     => ['exclude_if:auth_source,ldap', 'required', 'string', 'min:8', 'confirmed'],
            'permisos'                 => ['sometimes', 'array'],
            'permisos.*.sector_id'     => ['nullable', 'integer', 'exists:sector,sector_id'],
            'permisos.*.nivel'         => ['required', Rule::in(UsuarioPermiso::NIVELES)],
        ], $this->mensajes())->validate();

        $user = new UserRole();
        $user->username     = $data['username'];
        $user->display_name = $data['display_name'] ?? $data['username'];
        $user->email        = $data['email'] ?? null;
        $user->es_admin     = (bool) ($data['es_admin'] ?? false);
        $user->activo       = array_key_exists('activo', $data) ? (bool) $data['activo'] : true;
        $user->auth_source  = $data['auth_source'];
        $user->password     = $data['auth_source'] === 'local' ? $data['password'] : null; // el cast 'hashed' lo hashea
        $user->save();

        $this->sincronizarPermisos($user, $data['permisos'] ?? []);

        return response()->json(['data' => $user->load('permisos.sector:sector_id,nombre')], 201);
    }

    /**
     * PUT /api/usuarios/{username} — editar datos, permisos y/o estado.
     * El username es inmutable. La contraseña se cambia con el endpoint dedicado.
     */
    public function update(Request $request, string $username): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email'        => ['sometimes', 'nullable', 'email', 'max:200'],
            'es_admin'     => ['sometimes', 'boolean'],
            'activo'       => ['sometimes', 'boolean'],
            'permisos'                 => ['sometimes', 'array'],
            'permisos.*.sector_id'     => ['nullable', 'integer', 'exists:sector,sector_id'],
            'permisos.*.nivel'         => ['required', Rule::in(UsuarioPermiso::NIVELES)],
        ], $this->mensajes())->validate();

        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return $this->notFound();
        }

        // Cambios que dejarían al sistema sin administradores activos.
        $degrada   = array_key_exists('es_admin', $data) && !$data['es_admin'];
        $desactiva = array_key_exists('activo', $data) && !$data['activo'];
        if ($user->esAdmin() && ($degrada || $desactiva) && $this->esUltimoAdmin($username)) {
            return response()->json([
                'error'   => 'last_admin',
                'message' => 'No se puede degradar o desactivar al último administrador del sistema activo.',
            ], 409);
        }

        // Para usuarios LDAP, nombre y e-mail se sincronizan desde el directorio en cada login.
        if ($user->isLdap()) {
            unset($data['display_name'], $data['email']);
        }

        $permisos = $data['permisos'] ?? null;
        unset($data['permisos']);

        $user->fill($data);
        $user->save();

        if ($permisos !== null) {
            $this->sincronizarPermisos($user, $permisos);
        }

        return response()->json(['data' => $user->fresh()->load('permisos.sector:sector_id,nombre')]);
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

        if ($user->esAdmin() && $this->esUltimoAdmin($username)) {
            return response()->json([
                'error'   => 'last_admin',
                'message' => 'No se puede eliminar al último administrador del sistema activo.',
            ], 409);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    /**
     * Reemplaza los permisos del usuario por los indicados.
     *
     * Se descartan los redundantes: si una rama ya viene concedida desde un
     * ancestro con el mismo nivel o con uno mayor, repetirla abajo no agrega
     * nada y sólo confunde al leer la asignación.
     *
     * @param array<int, array{sector_id: ?int, nivel: string}> $permisos
     */
    private function sincronizarPermisos(UserRole $user, array $permisos): void
    {
        $utiles = [];
        foreach ($permisos as $p) {
            $sectorId = $p['sector_id'] ?? null;
            if ($this->heredaDeUnAncestro($permisos, $sectorId, $p['nivel'])) {
                continue;
            }
            // La clave evita duplicados sobre el mismo nodo.
            $utiles[$sectorId === null ? 'raiz' : (string) $sectorId] = [
                'sector_id' => $sectorId,
                'nivel'     => $p['nivel'],
            ];
        }

        DB::transaction(function () use ($user, $utiles) {
            $user->permisos()->delete();
            foreach ($utiles as $p) {
                $user->permisos()->create($p);
            }
        });
    }

    /**
     * ¿Otro permiso de la misma lista, sobre un ancestro del nodo, ya alcanza a
     * este con un nivel igual o mayor?
     *
     * @param array<int, array{sector_id: ?int, nivel: string}> $permisos
     */
    private function heredaDeUnAncestro(array $permisos, ?int $sectorId, string $nivel): bool
    {
        if ($sectorId === null) {
            return false; // la raíz no tiene ancestros
        }

        $ancestros = $this->ancestrosDe($sectorId);
        array_shift($ancestros); // el propio nodo no cuenta

        foreach ($permisos as $otro) {
            $otroSector = $otro['sector_id'] ?? null;
            $alcanza    = $otroSector === null || in_array($otroSector, $ancestros, true);
            $mandaMas   = $otro['nivel'] === UsuarioPermiso::NIVEL_ESCRITURA
                       || $otro['nivel'] === $nivel;

            if ($alcanza && $mandaMas) {
                return true;
            }
        }

        return false;
    }

    /** El nodo y sus ancestros, del más profundo al más alto. @return array<int> */
    private function ancestrosDe(int $sectorId): array
    {
        $ids    = [];
        $actual = $sectorId;
        while ($actual !== null && $this->arbol->existe($actual)) {
            $ids[]  = $actual;
            $actual = $this->arbol->padre($actual);
        }
        return $ids;
    }

    /**
     * Indica si, excluyendo al usuario dado, no quedan otros administradores
     * del sistema activos.
     */
    private function esUltimoAdmin(string $username): bool
    {
        return UserRole::where('es_admin', 1)
            ->where('activo', 1)
            ->where('username', '!=', $username)
            ->count() === 0;
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return [
            'permisos.*.nivel.in'          => 'El nivel del permiso debe ser lectura o escritura.',
            'permisos.*.sector_id.exists'  => 'El nodo indicado no existe en la estructura.',
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
