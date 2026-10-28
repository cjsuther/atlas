<?php

namespace App\Http\Controllers;

use App\Services\AprobacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja del aprobador: lo que espera aprobación en su alcance y la decisión
 * sobre cada cosa. La cantidad la pide cualquiera, para mostrarla en el menú;
 * el resto, sólo quien tiene la marca de aprobador.
 */
class AprobacionController extends Controller
{
    public function __construct(protected AprobacionService $service) {}

    /** GET /api/aprobaciones */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->es_aprobador) {
            return $this->noEsAprobador();
        }
        return response()->json(['data' => $this->service->bandeja($request->user())]);
    }

    /** GET /api/aprobaciones/cantidad */
    public function cantidad(Request $request): JsonResponse
    {
        return response()->json(['cantidad' => $this->service->cantidad($request->user())]);
    }

    /** POST /api/aprobaciones/{id}/aprobar */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->es_aprobador) {
            return $this->noEsAprobador();
        }
        $data = $request->validate([
            'observacion' => ['nullable', 'string', 'max:2000'],
        ]);
        $m = $this->service->aprobar($id, $request->user(), $data['observacion'] ?? null);
        return response()->json([
            'data'    => $m,
            'message' => 'Aprobado. El saldo ya lo refleja.',
        ]);
    }

    /** POST /api/aprobaciones/{id}/rechazar */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->es_aprobador) {
            return $this->noEsAprobador();
        }
        $data = $request->validate([
            'observacion' => ['required', 'string', 'max:2000'],
        ], [
            'observacion.required' => 'Escriba por qué lo rechaza: es lo que va a leer quien lo cargó.',
        ]);
        $m = $this->service->rechazar($id, $request->user(), $data['observacion']);
        return response()->json([
            'data'    => $m,
            'message' => 'Rechazado. No mueve el saldo y queda registrado con su observación.',
        ]);
    }

    private function noEsAprobador(): JsonResponse
    {
        return response()->json([
            'error'   => 'forbidden',
            'message' => 'Sólo los usuarios con la marca de aprobador pueden aprobar o rechazar.',
        ], 403);
    }
}
