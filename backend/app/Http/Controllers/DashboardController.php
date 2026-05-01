<?php

namespace App\Http\Controllers;

use App\Models\Contrato;
use App\Models\Estado;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/kpis
     */
    public function kpis(): JsonResponse
    {
        $totales = Contrato::select('estado_id', DB::raw('COUNT(*) AS total'))
            ->groupBy('estado_id')
            ->pluck('total', 'estado_id');

        $estados = Estado::orderBy('estado_id')->get(['estado_id', 'estado_nombre']);

        $hoy = Carbon::today();
        $vencen30 = Contrato::whereDate('fecha_vencimiento', '>=', $hoy)
            ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays(30))
            ->where('estado_id', 2) // En Ejecución
            ->count();
        $vencen60 = Contrato::whereDate('fecha_vencimiento', '>', $hoy->copy()->addDays(30))
            ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays(60))
            ->where('estado_id', 2)
            ->count();
        $vencen90 = Contrato::whereDate('fecha_vencimiento', '>', $hoy->copy()->addDays(60))
            ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays(90))
            ->where('estado_id', 2)
            ->count();

        $vencidos = Contrato::whereDate('fecha_vencimiento', '<', $hoy)
            ->where('estado_id', 2)
            ->count();

        $total = (int) Contrato::count();

        $por_estado = $estados->map(fn ($e) => [
            'estado_id'     => $e->estado_id,
            'estado_nombre' => $e->estado_nombre,
            'total'         => (int) ($totales[$e->estado_id] ?? 0),
        ])->values();

        return response()->json([
            'data' => [
                'total'      => $total,
                'por_estado' => $por_estado,
                'vencimientos' => [
                    'vencidos'  => $vencidos,
                    'dias_30'   => $vencen30,
                    'dias_60'   => $vencen60,
                    'dias_90'   => $vencen90,
                ],
            ],
        ]);
    }

    /**
     * GET /api/dashboard/vencimientos
     * Devuelve los próximos contratos a vencer (10 más cercanos en estado "En Ejecución").
     */
    public function vencimientos(): JsonResponse
    {
        $hoy = Carbon::today();

        $proximos = Contrato::with([
                'estado:estado_id,estado_nombre',
                'tipoContrato:id_tipo,tipo',
                'solicitante:solicitante_id,razon_social',
                'sector:sector_id,nombre',
            ])
            ->where('estado_id', 2)
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->orderBy('fecha_vencimiento', 'asc')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $proximos,
        ]);
    }
}
