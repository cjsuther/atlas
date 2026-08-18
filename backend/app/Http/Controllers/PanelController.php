<?php

namespace App\Http\Controllers;

use App\Models\UserRole;
use App\Services\PanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    public function __construct(protected PanelService $service) {}

    public function indicadores(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->indicadoresPrincipales($request->all())]);
    }

    public function calculados(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->indicadoresCalculados($request->all())]);
    }

    /**
     * GET /api/panel/saldos
     *
     * La agrupación (Gerencia de Área / Gerencia / Contrato) llega por query;
     * si no viene, se usa la que el usuario tenga configurada.
     */
    public function saldos(Request $request): JsonResponse
    {
        $filters = $request->all();

        if (empty($filters['agrupacion'])) {
            $user = $request->user();
            $filters['agrupacion'] = $user instanceof UserRole
                ? $user->saldos_agrupacion
                : 'gerencia';
        }

        return response()->json(['data' => $this->service->saldos($filters)]);
    }

    public function porUvt(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->porUvt($request->all())]);
    }

    public function porGerencia(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->porGerencia($request->all())]);
    }

    public function porTipo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->porTipo($request->all())]);
    }

    public function porEstado(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->porEstado($request->all())]);
    }

    public function porMoneda(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->porMoneda($request->all())]);
    }

    public function porAccion(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->porAccion($request->all())]);
    }

    public function vencimientos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->vencimientos($request->all())]);
    }

    public function rankings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->rankings($request->all())]);
    }
}
