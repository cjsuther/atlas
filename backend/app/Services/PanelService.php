<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
use App\Models\EstadoEjecucion;
use App\Models\EstadoPrincipal;
use App\Models\HistorialCambio;
use App\Models\TipoContratoPrincipal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores y agrupamientos del Panel de Control.
 * Todos los métodos aceptan un array de filtros con:
 *   - desde, hasta : recortan por created_at del contrato
 *   - moneda_base  : 'Peso' por defecto, para conversión de montos
 */
class PanelService
{
    /** Aplica filtros comunes (rango de fechas) sobre cualquier query. */
    private function applyDateRange(Builder $q, array $filters, string $col = 'created_at'): Builder
    {
        if (!empty($filters['desde'])) $q->whereDate($col, '>=', $filters['desde']);
        if (!empty($filters['hasta'])) $q->whereDate($col, '<=', $filters['hasta']);
        return $q;
    }

    private function principalQuery(array $filters): Builder
    {
        return $this->applyDateRange(ContratoPrincipal::query(), $filters);
    }

    private function ejecucionQuery(array $filters): Builder
    {
        return $this->applyDateRange(ContratoEjecucion::query(), $filters);
    }

    /** ---------------- Sección A: indicadores principales ---------------- */
    public function indicadoresPrincipales(array $filters): array
    {
        $hoy = Carbon::today();

        // Totales por estado de cada tipo de contrato
        $totalesP = $this->principalQuery($filters)->count();
        $totalesE = $this->ejecucionQuery($filters)->count();

        $estadosPrincipal = EstadoPrincipal::pluck('id', 'nombre');
        $idEnFirma   = $estadosPrincipal['En firma']   ?? null;
        $idEnEjec    = $estadosPrincipal['En ejecución'] ?? null;
        $idFinalizado = $estadosPrincipal['Finalizado'] ?? null;

        $enFirma = $idEnFirma
            ? $this->principalQuery($filters)->where('estado_id', $idEnFirma)->count()
            : 0;
        $enEjec = $idEnEjec
            ? $this->principalQuery($filters)->where('estado_id', $idEnEjec)->count()
            : 0;
        $finalizados = $idFinalizado
            ? $this->principalQuery($filters)->where('estado_id', $idFinalizado)->count()
            : 0;

        // Vencidos / con atraso (no finalizados con fecha_vencimiento < hoy)
        $vencidos = $this->principalQuery($filters)
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->when($idFinalizado, fn ($q) => $q->where('estado_id', '!=', $idFinalizado))
            ->count()
        + $this->ejecucionQuery($filters)
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
            ->count();

        $monedaBase = $filters['moneda_base'] ?? 'Peso';
        // Presupuestados ahora viven en contrato_ejecucion (ingresos y gastos).
        $sumPresupIngresos = $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_ingresos', $monedaBase);
        $sumPresupGastos   = $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_gastos',   $monedaBase);
        // Ejecutados ahora son la suma de movimientos por tipo (todos en pesos).
        $sumEjecIngresos = (float) DB::table('ejecucion_movimientos as m')
            ->join('contratos_ejecucion as ce', 'ce.id', '=', 'm.contrato_ejecucion_id')
            ->whereNull('m.deleted_at')->whereNull('ce.deleted_at')
            ->where('m.tipo', 'ingreso')
            ->when(!empty($filters['desde']), fn ($q) => $q->whereDate('ce.created_at', '>=', $filters['desde']))
            ->when(!empty($filters['hasta']), fn ($q) => $q->whereDate('ce.created_at', '<=', $filters['hasta']))
            ->sum('m.monto');
        $sumEjecGastos = (float) DB::table('ejecucion_movimientos as m')
            ->join('contratos_ejecucion as ce', 'ce.id', '=', 'm.contrato_ejecucion_id')
            ->whereNull('m.deleted_at')->whereNull('ce.deleted_at')
            ->where('m.tipo', 'gasto')
            ->when(!empty($filters['desde']), fn ($q) => $q->whereDate('ce.created_at', '>=', $filters['desde']))
            ->when(!empty($filters['hasta']), fn ($q) => $q->whereDate('ce.created_at', '<=', $filters['hasta']))
            ->sum('m.monto');

        $beneficio = $sumEjecIngresos - $sumEjecGastos;

        return [
            'totales' => [
                'contratos_principal'  => $totalesP,
                'contratos_ejecucion'  => $totalesE,
                'en_firma'             => $enFirma,
                'en_ejecucion'         => $enEjec,
                'finalizados'          => $finalizados,
                'vencidos'             => $vencidos,
                'con_atraso'           => $vencidos,
            ],
            'montos' => [
                'moneda_base'                  => $monedaBase,
                'presupuestado_ingresos_total' => round($sumPresupIngresos, 2),
                'presupuestado_gastos_total'   => round($sumPresupGastos, 2),
                'ejecutado_ingresos_total'     => round($sumEjecIngresos, 2),
                'ejecutado_gastos_total'       => round($sumEjecGastos, 2),
                'beneficio_total'              => round($beneficio, 2),
            ],
        ];
    }

    /** ---------------- Sección C: indicadores calculados ---------------- */
    public function indicadoresCalculados(array $filters): array
    {
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        // Duración promedio de firma: días entre fecha_apertura_expediente y
        // primer cambio a estado "Firmado" en historial_cambios.
        $diasFirma = $this->promedioDiasACambioEstado(
            'contratos_ejecucion',
            campoFecha: 'fecha_apertura_expediente',
            estadoNombre: 'Firmado',
            tablaEstados: 'estado_ejecucion',
            filters: $filters,
        );

        // Duración promedio de ejecución: entre cambio a "En ejecución" y "Finalizado".
        $diasEjec = $this->promedioDiasEntreEstados(
            'contratos_ejecucion',
            estadoInicial: 'En ejecución',
            estadoFinal: 'Finalizado',
            tablaEstados: 'estado_ejecucion',
            filters: $filters,
        );

        // Finalizados en término: contratos finalizados antes (o en) la fecha_vencimiento.
        $finalizadosEnTerminoP = $this->porcentajeFinalizadosEnTermino(
            ContratoPrincipal::class, 'estado_principal', $filters
        );
        $finalizadosEnTerminoE = $this->porcentajeFinalizadosEnTermino(
            ContratoEjecucion::class, 'estado_ejecucion', $filters
        );

        $vencSinCierre = $this->porcentajeVencidosSinCierre($filters);

        // % ejecución económica = total ejecutado (movimientos) / total presupuestado (ejecuciones).
        $sumPresup = $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_ingresos', $monedaBase)
                   + $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_gastos',   $monedaBase);
        $sumEjec = (float) DB::table('ejecucion_movimientos as m')
            ->join('contratos_ejecucion as ce', 'ce.id', '=', 'm.contrato_ejecucion_id')
            ->whereNull('m.deleted_at')->whereNull('ce.deleted_at')
            ->when(!empty($filters['desde']), fn ($q) => $q->whereDate('ce.created_at', '>=', $filters['desde']))
            ->when(!empty($filters['hasta']), fn ($q) => $q->whereDate('ce.created_at', '<=', $filters['hasta']))
            ->sum('m.monto');
        $pctEjec = $sumPresup > 0 ? round(($sumEjec / $sumPresup) * 100, 2) : null;

        return [
            'dias_firma_promedio'             => $diasFirma,
            'dias_ejecucion_promedio'         => $diasEjec,
            'porcentaje_finalizados_en_termino_principal' => $finalizadosEnTerminoP,
            'porcentaje_finalizados_en_termino_ejecucion' => $finalizadosEnTerminoE,
            'porcentaje_vencidos_sin_cierre'  => $vencSinCierre,
            'porcentaje_ejecucion_economica'  => $pctEjec,
            'moneda_base'                     => $monedaBase,
        ];
    }

    /** ---------------- Distribuciones ---------------- */
    public function porUvt(array $filters): array
    {
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $principal = $this->principalQuery($filters)
            ->select('uvt_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('uvt_id')
            ->with('uvt:uvt_id,siglas,nombre')
            ->get();

        $ejecucion = $this->ejecucionQuery($filters)
            ->select('uvt_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('uvt_id')
            ->with('uvt:uvt_id,siglas,nombre')
            ->get();

        return [
            'moneda_base'         => $monedaBase,
            'contratos_principal' => $principal->map(fn ($r) => [
                'uvt_id'   => $r->uvt_id,
                'siglas'   => optional($r->uvt)->siglas,
                'nombre'   => optional($r->uvt)->nombre,
                'cantidad' => (int) $r->cantidad,
            ]),
            'contratos_ejecucion' => $ejecucion->map(fn ($r) => [
                'uvt_id'   => $r->uvt_id,
                'siglas'   => optional($r->uvt)->siglas,
                'nombre'   => optional($r->uvt)->nombre,
                'cantidad' => (int) $r->cantidad,
            ]),
        ];
    }

    public function porGerencia(array $filters): array
    {
        $principal = $this->principalQuery($filters)
            ->select('gerencia', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('gerencia')->get();

        $ejecucion = $this->ejecucionQuery($filters)
            ->select('gerencia', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('gerencia')->get();

        return [
            'contratos_principal' => $principal,
            'contratos_ejecucion' => $ejecucion,
        ];
    }

    public function porTipo(array $filters): array
    {
        $principal = $this->principalQuery($filters)
            ->select('tipo_contrato_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('tipo_contrato_id')
            ->with('tipoContrato:id,sigla,nombre')->get();

        $ejecucion = $this->ejecucionQuery($filters)
            ->select('tipo_contrato_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('tipo_contrato_id')
            ->with('tipoContrato:id,sigla,nombre')->get();

        return [
            'contratos_principal' => $principal->map(fn ($r) => [
                'tipo_contrato_id' => $r->tipo_contrato_id,
                'sigla'            => optional($r->tipoContrato)->sigla,
                'nombre'           => optional($r->tipoContrato)->nombre,
                'cantidad'         => (int) $r->cantidad,
            ]),
            'contratos_ejecucion' => $ejecucion->map(fn ($r) => [
                'tipo_contrato_id' => $r->tipo_contrato_id,
                'sigla'            => optional($r->tipoContrato)->sigla,
                'nombre'           => optional($r->tipoContrato)->nombre,
                'cantidad'         => (int) $r->cantidad,
            ]),
        ];
    }

    public function porEstado(array $filters): array
    {
        $principal = $this->principalQuery($filters)
            ->select('estado_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('estado_id')
            ->with('estado:id,nombre')->get();

        $ejecucion = $this->ejecucionQuery($filters)
            ->select('estado_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('estado_id')
            ->with('estado:id,nombre')->get();

        return [
            'contratos_principal' => $principal->map(fn ($r) => [
                'estado_id' => $r->estado_id,
                'nombre'    => optional($r->estado)->nombre,
                'cantidad'  => (int) $r->cantidad,
            ]),
            'contratos_ejecucion' => $ejecucion->map(fn ($r) => [
                'estado_id' => $r->estado_id,
                'nombre'    => optional($r->estado)->nombre,
                'cantidad'  => (int) $r->cantidad,
            ]),
        ];
    }

    public function porMoneda(array $filters): array
    {
        $principal = $this->principalQuery($filters)
            ->select('moneda', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('moneda')->get();

        $ejecucion = $this->ejecucionQuery($filters)
            ->select('moneda', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('moneda')->get();

        return [
            'contratos_principal' => $principal,
            'contratos_ejecucion' => $ejecucion,
        ];
    }

    public function vencimientos(array $filters): array
    {
        $hoy = Carbon::today();
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $bucket = function (int $maxDias, ?int $minDias = null) use ($hoy) {
            return [
                'principal' => ContratoPrincipal::query()
                    ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
                    ->whereDate('fecha_vencimiento', '>=', $hoy)
                    ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays($maxDias))
                    ->when($minDias !== null, fn ($q) => $q->whereDate('fecha_vencimiento', '>', $hoy->copy()->addDays($minDias)))
                    ->count(),
                'ejecucion' => ContratoEjecucion::query()
                    ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
                    ->whereDate('fecha_vencimiento', '>=', $hoy)
                    ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays($maxDias))
                    ->when($minDias !== null, fn ($q) => $q->whereDate('fecha_vencimiento', '>', $hoy->copy()->addDays($minDias)))
                    ->count(),
            ];
        };

        return [
            'vencidos' => [
                'principal' => ContratoPrincipal::whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
                    ->whereDate('fecha_vencimiento', '<', $hoy)->count(),
                'ejecucion' => ContratoEjecucion::whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
                    ->whereDate('fecha_vencimiento', '<', $hoy)->count(),
            ],
            'dias_30' => $bucket(30),
            'dias_60' => $bucket(60, 30),
            'dias_90' => $bucket(90, 60),
            'moneda_base' => $monedaBase,
        ];
    }

    public function rankings(array $filters): array
    {
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $rankGerencia = $this->principalQuery($filters)
            ->select('gerencia', DB::raw('COUNT(*) as cantidad'))
            ->whereNotNull('gerencia')
            ->groupBy('gerencia')
            ->orderByDesc('cantidad')
            ->limit(20)
            ->get();

        // Ranking UVT por cantidad y monto (sumando ejecutado de ejecucion)
        $rankUvtCantidad = ContratoPrincipal::query()
            ->select('uvt_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('uvt_id')
            ->with('uvt:uvt_id,siglas,nombre')
            ->orderByDesc('cantidad')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'uvt_id'   => $r->uvt_id,
                'siglas'   => optional($r->uvt)->siglas,
                'nombre'   => optional($r->uvt)->nombre,
                'cantidad' => (int) $r->cantidad,
            ]);

        $rankUvtMonto = $this->montoPorUvt($filters, $monedaBase);

        return [
            'gerencias_por_cantidad' => $rankGerencia,
            'uvt_por_cantidad'       => $rankUvtCantidad,
            'uvt_por_monto'          => $rankUvtMonto,
            'moneda_base'            => $monedaBase,
        ];
    }

    /** ---------------- Helpers privados ---------------- */

    /** Suma `monto * cotizacion` para llevar todo a la moneda base (Peso). */
    private function sumarMontos(Builder $q, string $col, string $monedaBase): float
    {
        $rows = $q->select('moneda', 'cotizacion', $col)->get();
        $total = 0.0;
        foreach ($rows as $r) {
            $monto = (float) ($r->{$col} ?? 0);
            if ($monto <= 0) continue;
            if ($r->moneda === $monedaBase) {
                $total += $monto;
            } else {
                $cot = (float) ($r->cotizacion ?? 0);
                if ($cot > 0) $total += $monto * $cot;
                else $total += $monto; // sin cotización, asume 1:1
            }
        }
        return $total;
    }

    private function montoPorUvt(array $filters, string $monedaBase): \Illuminate\Support\Collection
    {
        // Presupuestados desde contratos_ejecucion (ingresos + gastos)
        $rowsE = $this->ejecucionQuery($filters)
            ->select('id', 'uvt_id', 'moneda', 'cotizacion', 'monto_presupuestado_ingresos', 'monto_presupuestado_gastos')
            ->with('uvt:uvt_id,siglas,nombre')
            ->get();

        // Suma de movimientos en pesos (ejecutado) por contrato_ejecucion_id
        $sumsMov = DB::table('ejecucion_movimientos as m')
            ->join('contratos_ejecucion as ce', 'ce.id', '=', 'm.contrato_ejecucion_id')
            ->whereNull('m.deleted_at')->whereNull('ce.deleted_at')
            ->when(!empty($filters['desde']), fn ($q) => $q->whereDate('ce.created_at', '>=', $filters['desde']))
            ->when(!empty($filters['hasta']), fn ($q) => $q->whereDate('ce.created_at', '<=', $filters['hasta']))
            ->select('m.contrato_ejecucion_id', DB::raw('SUM(m.monto) as total'))
            ->groupBy('m.contrato_ejecucion_id')
            ->pluck('total', 'm.contrato_ejecucion_id');

        $byUvt = [];
        foreach ($rowsE as $r) {
            $key = $r->uvt_id ?? 0;
            if (!isset($byUvt[$key])) {
                $byUvt[$key] = [
                    'uvt_id'        => $r->uvt_id,
                    'siglas'        => optional($r->uvt)->siglas,
                    'nombre'        => optional($r->uvt)->nombre,
                    'presupuestado' => 0.0,
                    'ejecutado'     => 0.0,
                ];
            }
            $factor = ($r->moneda === $monedaBase || !$r->cotizacion) ? 1 : (float) $r->cotizacion;
            $byUvt[$key]['presupuestado'] += ((float) ($r->monto_presupuestado_ingresos ?? 0)
                                            + (float) ($r->monto_presupuestado_gastos ?? 0)) * $factor;
            // Ejecutados (movimientos) ya están en pesos: no aplica el factor de cotización del contrato.
            $byUvt[$key]['ejecutado'] += (float) ($sumsMov[$r->id] ?? 0);
        }
        $list = collect(array_values($byUvt))->map(function ($r) {
            $r['presupuestado'] = round($r['presupuestado'], 2);
            $r['ejecutado']     = round($r['ejecutado'], 2);
            return $r;
        })->sortByDesc('ejecutado')->values();
        return $list;
    }

    private function porcentajeFinalizadosEnTermino(string $modelClass, string $tablaEstados, array $filters): ?float
    {
        $q = $modelClass::query();
        $this->applyDateRange($q, $filters);

        $finalizados = (clone $q)
            ->whereHas('estado', fn ($e) => $e->where('nombre', 'Finalizado'))
            ->count();
        if ($finalizados === 0) return null;

        $enTermino = (clone $q)
            ->whereHas('estado', fn ($e) => $e->where('nombre', 'Finalizado'))
            ->whereNotNull('fecha_finalizacion')
            ->whereNotNull('fecha_vencimiento')
            ->whereColumn('fecha_finalizacion', '<=', 'fecha_vencimiento')
            ->count();

        return round(($enTermino / $finalizados) * 100, 2);
    }

    private function porcentajeVencidosSinCierre(array $filters): ?float
    {
        $hoy = Carbon::today();

        $totalP = $this->principalQuery($filters)->count();
        $totalE = $this->ejecucionQuery($filters)->count();
        $total  = $totalP + $totalE;
        if ($total === 0) return null;

        $vencP = $this->principalQuery($filters)
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
            ->count();
        $vencE = $this->ejecucionQuery($filters)
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
            ->count();

        return round((($vencP + $vencE) / $total) * 100, 2);
    }

    /** Promedio de días entre el campoFecha del registro y el primer cambio
     *  de estado al estado indicado, según historial_cambios. */
    private function promedioDiasACambioEstado(
        string $tabla,
        string $campoFecha,
        string $estadoNombre,
        string $tablaEstados,
        array $filters,
    ): ?float {
        $estadoId = DB::table($tablaEstados)->where('nombre', $estadoNombre)->value('id');
        if (!$estadoId) return null;

        $modelClass = $tabla === 'contratos_principal' ? ContratoPrincipal::class : ContratoEjecucion::class;
        $contratos = $modelClass::query();
        $this->applyDateRange($contratos, $filters);
        $contratos = $contratos->whereNotNull($campoFecha)->get([$modelClass === ContratoPrincipal::class ? 'id' : 'id', $campoFecha]);

        $dias = [];
        foreach ($contratos as $c) {
            $h = HistorialCambio::where('tabla', $tabla)
                ->where('registro_id', $c->id)
                ->where('campo_modificado', 'estado_id')
                ->where('valor_nuevo', (string) $estadoId)
                ->orderBy('fecha', 'asc')
                ->first();
            if (!$h) continue;
            $diff = Carbon::parse($c->{$campoFecha})->diffInDays(Carbon::parse($h->fecha));
            if ($diff >= 0) $dias[] = $diff;
        }

        return $dias ? round(array_sum($dias) / count($dias), 1) : null;
    }

    /** Promedio de días entre dos cambios de estado consecutivos en historial. */
    private function promedioDiasEntreEstados(
        string $tabla,
        string $estadoInicial,
        string $estadoFinal,
        string $tablaEstados,
        array $filters,
    ): ?float {
        $idIni = DB::table($tablaEstados)->where('nombre', $estadoInicial)->value('id');
        $idFin = DB::table($tablaEstados)->where('nombre', $estadoFinal)->value('id');
        if (!$idIni || !$idFin) return null;

        $modelClass = $tabla === 'contratos_principal' ? ContratoPrincipal::class : ContratoEjecucion::class;
        $contratos = $modelClass::query();
        $this->applyDateRange($contratos, $filters);
        $contratos = $contratos->get(['id']);

        $dias = [];
        foreach ($contratos as $c) {
            $hIni = HistorialCambio::where('tabla', $tabla)
                ->where('registro_id', $c->id)
                ->where('campo_modificado', 'estado_id')
                ->where('valor_nuevo', (string) $idIni)
                ->orderBy('fecha', 'asc')->first();
            $hFin = HistorialCambio::where('tabla', $tabla)
                ->where('registro_id', $c->id)
                ->where('campo_modificado', 'estado_id')
                ->where('valor_nuevo', (string) $idFin)
                ->orderBy('fecha', 'asc')->first();
            if (!$hIni || !$hFin) continue;
            $diff = Carbon::parse($hIni->fecha)->diffInDays(Carbon::parse($hFin->fecha));
            if ($diff >= 0) $dias[] = $diff;
        }

        return $dias ? round(array_sum($dias) / count($dias), 1) : null;
    }
}
