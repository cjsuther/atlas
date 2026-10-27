<?php

namespace App\Http\Controllers;

use App\Exports\ExpedientesExport;
use App\Http\Requests\ExpedienteRequest;
use App\Models\Expediente;
use App\Services\ExpedienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpedienteController extends Controller
{
    public function __construct(protected ExpedienteService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->paginate($request->all()));
    }

    /** GET /api/expedientes/opciones — todos los números, para el selector del movimiento */
    public function opciones(): JsonResponse
    {
        return response()->json(['data' => $this->service->opciones()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $withTrashed = (bool) $request->input('mostrar_baja');
        $c = $this->service->find($id, $withTrashed);
        if (!$c) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Expediente no encontrado.',
            ], 404);
        }
        return response()->json(['data' => $c]);
    }

    public function store(ExpedienteRequest $request): JsonResponse
    {
        $c = $this->service->create($request->validated());
        return response()->json(['data' => $this->service->find($c->id)], 201);
    }

    public function update(ExpedienteRequest $request, int $id): JsonResponse
    {
        $actual = $this->service->find($id);
        if (!$actual) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Expediente no encontrado.',
            ], 404);
        }
        // Se ve desde otra rama por sus movimientos, pero lo modifica la suya.
        if (!$actual->editable) {
            return $this->sinPermiso();
        }
        $this->service->update($id, $request->validated());
        return response()->json(['data' => $this->service->find($id)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $actual = $this->service->find($id);
        if ($actual && !$actual->editable) {
            return $this->sinPermiso();
        }
        if (!$this->service->softDelete($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Expediente no encontrado.',
            ], 404);
        }
        return response()->json([
            'message' => 'Contrato dado de baja.',
            'data'    => $this->service->find($id, true),
        ]);
    }


    private function sinPermiso(): JsonResponse
    {
        return response()->json([
            'error'   => 'forbidden',
            'message' => 'El expediente lo modifica la rama de su cuenta de origen.',
        ], 403);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filename = 'atlas-expedientes-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new ExpedientesExport($request->all(), $this->service), $filename);
    }
}
