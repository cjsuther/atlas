<?php

namespace App\Http\Controllers;

use App\Models\ContratoEjecucion;
use App\Models\EjecucionMovimiento;
use App\Models\HistorialCambio;
use App\Services\AccessScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistorialController extends Controller
{
    private const TABLAS_PERMITIDAS = [
        'contratos_principal',
        'contratos_ejecucion',
        'ejecucion_movimientos',
    ];

    public function __construct(protected AccessScopeService $scope) {}

    /** GET /api/historial/{tabla}/{id} */
    public function show(Request $request, string $tabla, int $id): JsonResponse
    {
        if (!in_array($tabla, self::TABLAS_PERMITIDAS, true)) {
            return response()->json([
                'error'   => 'invalid_table',
                'message' => 'Tabla no permitida.',
                'permitidas' => self::TABLAS_PERMITIDAS,
            ], 422);
        }

        if (!$this->puedeVer($tabla, $id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Registro no encontrado.',
            ], 404);
        }

        $perPage = max(1, min((int) $request->input('per_page', 50), 200));

        $items = HistorialCambio::where('tabla', $tabla)
            ->where('registro_id', $id)
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json($items);
    }

    /**
     * El historial es tan reservado como el registro al que pertenece: se
     * consulta sólo si el contrato involucrado está dentro del alcance.
     */
    private function puedeVer(string $tabla, int $id): bool
    {
        return match ($tabla) {
            'contratos_ejecucion' => $this->contratoVisible($id),
            'ejecucion_movimientos' => (function () use ($id) {
                $m = EjecucionMovimiento::withTrashed()->find($id);
                return $m !== null && $this->contratoVisible((int) $m->contrato_ejecucion_id);
            })(),
            // Los contratos principales ya no se gestionan: su historial queda
            // disponible sólo para el administrador de sistema.
            'contratos_principal' => (bool) $this->scope->usuario()?->isAdminSistema(),
            default => false,
        };
    }

    private function contratoVisible(int $contratoId): bool
    {
        $contrato = ContratoEjecucion::withTrashed()->find($contratoId);
        return $contrato !== null && $this->scope->puedeVerContrato($contrato);
    }
}
