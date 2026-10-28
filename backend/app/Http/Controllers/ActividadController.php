<?php

namespace App\Http\Controllers;

use App\Models\LogActividad;
use App\Services\AccessScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Log de actividades: quién cargó, pidió, aprobó o rechazó, cuándo y con qué
 * observación. Es tan reservado como las cuentas: cada uno ve lo de las
 * cuentas de su alcance.
 */
class ActividadController extends Controller
{
    public function __construct(protected AccessScopeService $scope) {}

    /** GET /api/actividades */
    public function index(Request $request): JsonResponse
    {
        $q = LogActividad::query()
            ->leftJoin('user_roles as u', 'u.username', '=', 'log_actividades.usuario')
            ->leftJoin('cuentas_operativas as c', 'c.id', '=', 'log_actividades.cuenta_operativa_id')
            ->select('log_actividades.*', 'u.display_name as usuario_nombre', 'c.nombre as cuenta_nombre');

        $visibles = $this->scope->cuentasVisibles();
        if ($visibles !== null) {
            $q->whereIn('log_actividades.cuenta_operativa_id', $visibles ?: [0]);
        }

        if ($desde = $request->query('desde')) {
            $q->where('log_actividades.fecha', '>=', $desde . ' 00:00:00');
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('log_actividades.fecha', '<=', $hasta . ' 23:59:59');
        }
        if ($accion = $request->query('accion')) {
            $q->where('log_actividades.accion', $accion);
        }
        if ($cuenta = $request->query('cuenta_operativa_id')) {
            $q->where('log_actividades.cuenta_operativa_id', (int) $cuenta);
        }
        if ($registro = $request->query('movimiento_id')) {
            $q->where('log_actividades.tabla', 'ejecucion_movimientos')
              ->where('log_actividades.registro_id', (int) $registro);
        }
        if ($usuario = trim((string) $request->query('usuario'))) {
            $q->where(fn ($w) => $w->where('log_actividades.usuario', 'like', "%{$usuario}%")
                                   ->orWhere('u.display_name', 'like', "%{$usuario}%"));
        }
        if ($search = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('log_actividades.descripcion', 'like', "%{$search}%")
                                   ->orWhere('log_actividades.observacion', 'like', "%{$search}%")
                                   ->orWhere('c.nombre', 'like', "%{$search}%"));
        }

        $perPage = max(1, min((int) $request->query('per_page', 50), 200));
        $pagina = $q->orderByDesc('log_actividades.fecha')->orderByDesc('log_actividades.id')->paginate($perPage);

        $pagina->getCollection()->transform(fn ($a) => array_merge($a->toArray(), [
            'fecha'        => $a->fecha?->toIso8601String(),
            'accion_label' => LogActividad::ETIQUETAS[$a->accion] ?? $a->accion,
        ]));

        return response()->json(array_merge($pagina->toArray(), [
            'acciones' => collect(LogActividad::ETIQUETAS)
                ->map(fn ($etiqueta, $value) => ['value' => $value, 'etiqueta' => $etiqueta])
                ->values(),
        ]));
    }
}
