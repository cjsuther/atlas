<?php

namespace App\Http\Middleware;

use App\Models\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserva una ruta a los administradores del sistema.
 *
 * Ya no hay roles: el alcance sobre los expedientes sale de los permisos que
 * el usuario tiene sobre el árbol. Lo que este atributo habilita es otra cosa,
 * la configuración del sistema: estructura, cuentas, catálogos, usuarios y
 * respaldos.
 *
 * Uso en rutas:
 *   Route::middleware(['auth:sanctum', 'admin'])->...
 */
class EsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Debe iniciar sesión.',
            ], 401);
        }

        if (!$user->activo) {
            return response()->json([
                'error'   => 'inactive_user',
                'message' => 'El usuario está inactivo.',
            ], 403);
        }

        if (!($user instanceof UserRole) || !$user->esAdmin()) {
            return response()->json([
                'error'   => 'forbidden',
                'message' => 'Esta acción es exclusiva del administrador del sistema.',
            ], 403);
        }

        return $next($request);
    }
}
