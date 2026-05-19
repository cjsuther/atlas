<?php

namespace App\Http\Controllers;

use App\Http\Requests\EjecucionMovimientoRequest;
use App\Models\ContratoEjecucion;
use App\Models\EjecucionMovimiento;
use App\Services\EjecucionMovimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EjecucionMovimientoController extends Controller
{
    public function __construct(protected EjecucionMovimientoService $service) {}

    /** GET /api/contratos-ejecucion/{id}/movimientos */
    public function indexForContrato(Request $request, int $contratoEjecucionId): JsonResponse
    {
        if (!ContratoEjecucion::find($contratoEjecucionId)) {
            return $this->notFoundContrato();
        }
        return response()->json(
            $this->service->listForContrato($contratoEjecucionId, $request->all())
        );
    }

    /** POST /api/contratos-ejecucion/{id}/movimientos */
    public function storeForContrato(EjecucionMovimientoRequest $request, int $contratoEjecucionId): JsonResponse
    {
        if (!ContratoEjecucion::find($contratoEjecucionId)) {
            return $this->notFoundContrato();
        }
        $m = $this->service->create($contratoEjecucionId, $request->validated(), $request->file('factura'));
        return response()->json(['data' => $m], 201);
    }

    /** GET /api/movimientos/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $withTrashed = (bool) $request->input('mostrar_baja');
        $m = $this->service->find($id, $withTrashed);
        if (!$m) return $this->notFound();
        return response()->json(['data' => $m]);
    }

    /** PUT /api/movimientos/{id} */
    public function update(EjecucionMovimientoRequest $request, int $id): JsonResponse
    {
        if (!$this->service->find($id)) return $this->notFound();
        $m = $this->service->update(
            $id,
            $request->validated(),
            $request->file('factura'),
            (bool) $request->input('eliminar_factura'),
        );
        return response()->json(['data' => $m]);
    }

    /** DELETE /api/movimientos/{id} — baja lógica */
    public function destroy(int $id): JsonResponse
    {
        if (!$this->service->softDelete($id)) return $this->notFound();
        return response()->json(['message' => 'Movimiento dado de baja.']);
    }

    /** GET /api/movimientos/{id}/factura — descarga el archivo */
    public function descargarFactura(int $id): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $m = $this->service->find($id, true);
        if (!$m) return $this->notFound();
        $path = $m->getRawOriginal('factura_path');
        if (!$path) {
            return response()->json([
                'error'   => 'no_file',
                'message' => 'Este movimiento no tiene factura adjunta.',
            ], 404);
        }
        $disk = Storage::disk(EjecucionMovimientoService::FACTURA_DISK);
        if (!$disk->exists($path)) {
            return response()->json([
                'error'   => 'file_missing',
                'message' => 'La factura ya no está disponible en el servidor.',
            ], 410);
        }
        return $disk->download($path, $m->factura_original_name ?? basename($path));
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => 'Movimiento no encontrado.',
        ], 404);
    }

    private function notFoundContrato(): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => 'Contrato de ejecución no encontrado.',
        ], 404);
    }
}
