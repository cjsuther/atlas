<?php

namespace App\Http\Middleware;

use App\Models\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el paso a los usuarios con rol `sin_acceso`.
 *
 * Son los que llegan por LDAP: el directorio confirma quiénes son, pero hasta
 * que un administrador les asigne un rol y una Gerencia de Área no pueden ver
 * nada. Se les deja consultar su propio usuario y cerrar sesión para que la
 * aplicación pueda explicarles por qué no ven nada.
 */
class EnsureHasAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof UserRole && $user->sinAcceso()) {
            return response()->json([
                'error'   => 'sin_acceso',
                'message' => 'Su usuario todavía no tiene permisos asignados. '
                           . 'Contacte al administrador del sistema.',
            ], 403);
        }

        return $next($request);
    }
}
