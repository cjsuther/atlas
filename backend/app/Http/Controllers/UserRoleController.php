<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRoleController extends Controller
{
    /**
     * GET /api/usuarios — listado paginado con búsqueda.
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

        $orderBy  = in_array($request->query('order_by'), ['username', 'rol', 'last_login', 'activo'], true)
            ? $request->query('order_by') : 'username';
        $orderDir = strtolower($request->query('order_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $q->orderBy($orderBy, $orderDir);

        $perPage = max(1, min((int) $request->query('per_page', 20), 200));
        return response()->json($q->paginate($perPage));
    }

    /**
     * PUT /api/usuarios/{username} — cambiar rol y/o estado activo.
     */
    public function update(Request $request, string $username): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'rol'    => ['sometimes', 'in:admin,operador,consulta'],
            'activo' => ['sometimes', 'boolean'],
        ])->validate();

        $user = UserRole::where('username', $username)->first();
        if (!$user) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        // Evitar que un admin se quite a sí mismo el rol mientras es el único activo
        if (isset($data['rol']) && $data['rol'] !== 'admin' && $user->isAdmin()) {
            $otherActiveAdmins = UserRole::where('rol', 'admin')
                ->where('activo', 1)
                ->where('username', '!=', $username)
                ->count();
            if ($otherActiveAdmins === 0) {
                return response()->json([
                    'error'   => 'last_admin',
                    'message' => 'No se puede degradar al último administrador activo.',
                ], 409);
            }
        }

        $user->fill($data);
        $user->save();

        return response()->json(['data' => $user]);
    }
}
