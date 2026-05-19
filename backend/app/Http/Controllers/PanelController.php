<?php

namespace App\Http\Controllers;

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

    public function vencimientos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->vencimientos($request->all())]);
    }

    public function rankings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->rankings($request->all())]);
    }
}
