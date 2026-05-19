<?php

namespace App\Http\Controllers;

use App\Models\HistorialCambio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistorialController extends Controller
{
    private const TABLAS_PERMITIDAS = [
        'contratos_principal',
        'contratos_ejecucion',
        'ejecucion_movimientos',
    ];

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

        $perPage = max(1, min((int) $request->input('per_page', 50), 200));

        $items = HistorialCambio::where('tabla', $tabla)
            ->where('registro_id', $id)
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json($items);
    }
}
