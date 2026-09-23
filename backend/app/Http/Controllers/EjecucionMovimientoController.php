<?php

namespace App\Http\Controllers;

use App\Http\Requests\EjecucionMovimientoRequest;
use App\Models\Expediente;
use App\Models\EjecucionMovimiento;
use App\Services\AccessScopeService;
use App\Services\EjecucionMovimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EjecucionMovimientoController extends Controller
{
    public function __construct(
        protected EjecucionMovimientoService $service,
        protected AccessScopeService $scope,
    ) {}

    /** GET /api/cuentas-operativas/{id}/movimientos — historial de la cuenta */
    public function indexForCuenta(Request $request, int $cuentaOperativaId): JsonResponse
    {
        if (!$this->scope->puedeVerCuenta($cuentaOperativaId)) {
            return $this->notFoundCuenta();
        }
        return response()->json(
            $this->service->listForCuenta($cuentaOperativaId, $request->all())
        );
    }

    /** POST /api/cuentas-operativas/{id}/movimientos */
    public function storeForCuenta(EjecucionMovimientoRequest $request, int $cuentaOperativaId): JsonResponse
    {
        if (!$this->scope->puedeUsarCuenta($cuentaOperativaId)) {
            return $this->notFoundCuenta();
        }
        $m = $this->service->create($cuentaOperativaId, $request->validated(), $request->file('factura'));
        return response()->json(['data' => $m], 201);
    }

    /** GET /api/expedientes/{id}/movimientos — lo registrado contra ese contrato */
    public function indexForContrato(Request $request, int $expedienteId): JsonResponse
    {
        if (!$this->contratoAccesible($expedienteId)) {
            return $this->notFoundContrato();
        }
        return response()->json(
            $this->service->listForContrato($expedienteId, $request->all())
        );
    }

    /** GET /api/movimientos/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $withTrashed = (bool) $request->input('mostrar_baja');
        $m = $this->service->find($id, $withTrashed);
        if (!$m || !$this->movimientoAccesible($m)) return $this->notFound();
        return response()->json(['data' => $m]);
    }

    /** PUT /api/movimientos/{id} */
    public function update(EjecucionMovimientoRequest $request, int $id): JsonResponse
    {
        $actual = $this->service->find($id);
        if (!$actual || !$this->movimientoEditable($actual)) return $this->notFound();
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
        $actual = $this->service->find($id);
        if (!$actual || !$this->movimientoEditable($actual)) return $this->notFound();
        if (!$this->service->softDelete($id)) return $this->notFound();
        return response()->json(['message' => 'Movimiento dado de baja.']);
    }

    /** GET /api/movimientos/{id}/factura — descarga el archivo */
    public function descargarFactura(int $id): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $m = $this->service->find($id, true);
        if (!$m || !$this->movimientoAccesible($m)) return $this->notFound();
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

    /**
     * Los movimientos son tan reservados como la cuenta en la que están:
     * fuera del alcance del usuario se responde "no encontrado".
     */
    private function contratoAccesible(int $expedienteId): bool
    {
        $contrato = Expediente::withTrashed()->find($expedienteId);
        return $contrato !== null && $this->scope->puedeVerContrato($contrato);
    }

    private function movimientoAccesible(EjecucionMovimiento $m): bool
    {
        return $this->scope->puedeVerCuenta((int) $m->cuenta_operativa_id);
    }

    private function movimientoEditable(EjecucionMovimiento $m): bool
    {
        return $this->scope->puedeUsarCuenta((int) $m->cuenta_operativa_id);
    }

    private function notFoundCuenta(): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => 'Cuenta operativa no encontrada.',
        ], 404);
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
            'message' => 'Contrato no encontrado.',
        ], 404);
    }
}
