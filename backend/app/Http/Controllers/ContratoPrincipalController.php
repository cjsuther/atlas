<?php

namespace App\Http\Controllers;

use App\Exports\ContratosPrincipalExport;
use App\Http\Requests\ContratoPrincipalRequest;
use App\Services\ContratoPrincipalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContratoPrincipalController extends Controller
{
    public function __construct(protected ContratoPrincipalService $service) {}

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
                'message' => 'Contrato principal no encontrado.',
            ], 404);
        }
        return response()->json(['data' => $c]);
    }

    public function store(ContratoPrincipalRequest $request): JsonResponse
    {
        $c = $this->service->create($request->validated());
        return response()->json(['data' => $this->service->find($c->id)], 201);
    }

    public function update(ContratoPrincipalRequest $request, int $id): JsonResponse
    {
        if (!$this->service->find($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Contrato principal no encontrado.',
            ], 404);
        }
        $this->service->update($id, $request->validated());
        return response()->json(['data' => $this->service->find($id)]);
    }

    /** Baja lógica. */
    public function destroy(int $id): JsonResponse
    {
        if (!$this->service->softDelete($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Contrato principal no encontrado.',
            ], 404);
        }
        return response()->json([
            'message' => 'Contrato principal dado de baja.',
            'data'    => $this->service->find($id, true),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filename = 'atlas-contratos-principal-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new ContratosPrincipalExport($request->all(), $this->service), $filename);
    }
}
