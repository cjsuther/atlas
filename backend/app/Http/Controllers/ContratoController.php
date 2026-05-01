<?php

namespace App\Http\Controllers;

use App\Exports\ContratosExport;
use App\Services\ContratoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContratoController extends Controller
{
    public function __construct(protected ContratoService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service->paginate($request->all());
        return response()->json($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $contrato = $this->service->find($id);
        if (!$contrato) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Contrato no encontrado.',
            ], 404);
        }
        return response()->json(['data' => $contrato]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), $this->rules())->validate();
        $contrato = $this->service->create($data);
        return response()->json(['data' => $this->service->find($contrato->id_cto)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->service->find($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Contrato no encontrado.',
            ], 404);
        }
        $data = Validator::make($request->all(), $this->rules($id))->validate();
        $this->service->update($id, $data);
        return response()->json(['data' => $this->service->find($id)]);
    }

    /**
     * Baja lógica → estado "Sin efecto".
     */
    public function destroy(int $id): JsonResponse
    {
        $contrato = $this->service->softDelete($id);
        if (!$contrato) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Contrato no encontrado.',
            ], 404);
        }
        return response()->json([
            'message' => 'Contrato dado de baja (Sin efecto).',
            'data'    => $this->service->find($id),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filename = 'atlas-contratos-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new ContratosExport($request->all(), $this->service), $filename);
    }

    private function rules(?int $id = null): array
    {
        return [
            'nombre_proy'                => ['required', 'string', 'max:500'],
            'dependencia_contractual_id' => ['nullable', 'integer', 'exists:contratos,id_cto'],
            'operatoria_id'              => ['nullable', 'integer'],
            'fecha_expediente'           => ['nullable', 'date'],
            'estado_id'                  => ['nullable', 'integer', 'exists:estado,estado_id'],
            'expediente'                 => ['nullable', 'string', 'max:200'],
            'solicitud_sector_gde'       => ['nullable', 'string', 'max:300'],
            'descripcion_objeto'         => ['nullable', 'string'],
            'tipo_de_contrato_id'        => ['nullable', 'integer', 'exists:tipo_de_contrato,id_tipo'],
            'observaciones'              => ['nullable', 'string'],
            'solicitante_id'             => ['nullable', 'integer', 'exists:solicitantes,solicitante_id'],
            'uvt_id'                     => ['nullable', 'integer', 'exists:uvt,uvt_id'],
            'sector_id'                  => ['nullable', 'integer', 'exists:sector,sector_id'],
            'gerencia'                   => ['nullable', 'string', 'max:200'],
            'gerencia_area'              => ['nullable', 'string', 'max:200'],
            'fecha_firma'                => ['nullable', 'date'],
            'fecha_inicio'               => ['nullable', 'date'],
            'fecha_vencimiento'          => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_finalizado'           => ['nullable', 'date'],
            'duracion_meses'             => ['nullable', 'integer', 'min:0'],
            'atraso_meses'               => ['nullable', 'integer', 'min:0'],
            'prorroga'                   => ['nullable', 'boolean'],
            'renovacion_automatica'      => ['nullable', 'boolean'],
            'acta_finalizacion'          => ['nullable', 'string', 'max:500'],
            'resp1_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'resp2_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'caja_bas'                   => ['nullable', 'string', 'max:200'],
            'resp_caja'                  => ['nullable', 'string', 'max:200'],
            'monto_pesos'                => ['nullable', 'numeric', 'min:0'],
            'monto_usd'                  => ['nullable', 'numeric', 'min:0'],
            'monto_euros'                => ['nullable', 'numeric', 'min:0'],
            'monto_otro'                 => ['nullable', 'numeric', 'min:0'],
            'moneda_otro'                => ['nullable', 'string', 'max:50'],
            'automatico_ejecucion'       => ['nullable', 'boolean'],
            'automatico_finalizado'      => ['nullable', 'boolean'],
        ];
    }
}
