<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que valida que el usuario autenticado tenga uno de los roles indicados.
 *
 * Uso en rutas:
 *   Route::middleware(['auth:sanctum', 'role:admin'])->...
 *   Route::middleware(['auth:sanctum', 'role:admin,operador'])->...
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
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

        if (empty($roles)) {
            return $next($request);
        }

        if (!$user->hasRole(...$roles)) {
            return response()->json([
                'error'   => 'forbidden',
                'message' => 'No tiene permisos suficientes para esta acción.',
                'required_roles' => $roles,
            ], 403);
        }

        return $next($request);
    }
}
