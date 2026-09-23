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
        if (!$this->service->find($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Expediente no encontrado.',
            ], 404);
        }
        $this->service->update($id, $request->validated());
        return response()->json(['data' => $this->service->find($id)]);
    }

    public function destroy(int $id): JsonResponse
    {
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


    public function export(Request $request): BinaryFileResponse
    {
        $filename = 'atlas-expedientes-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new ExpedientesExport($request->all(), $this->service), $filename);
    }
}
