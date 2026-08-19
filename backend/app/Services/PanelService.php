<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\HistorialCambio;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores y agrupamientos del Panel de Control.
 *
 * Todo lo que devuelve este servicio está recortado al alcance del usuario:
 * los saldos y registros de una Gerencia de Área no se ven desde otra.
 *
 * Filtros comunes:
 *   - desde, hasta      : recortan por created_at del contrato
 *   - moneda_base       : 'Peso' por defecto, para conversión de montos
 *   - sector_id         : acota a un sector dentro del alcance
 *   - gerencia_area_id  : acota a una Gerencia de Área (y sus subsectores)
 */
class PanelService
{
    /** Agrupaciones admitidas para la vista de saldos. */
    public const AGRUPACIONES = ['gerencia_area', 'subsector', 'contrato'];

    public function __construct(
        protected AccessScopeService $scope,
        protected SectorTree $arbol,
    ) {
    }

    /** Aplica filtros comunes (rango de fechas) sobre cualquier query. */
    private function applyDateRange(Builder $q, array $filters, string $col = 'created_at'): Builder
    {
        if (!empty($filters['desde'])) $q->whereDate($col, '>=', $filters['desde']);
        if (!empty($filters['hasta'])) $q->whereDate($col, '<=', $filters['hasta']);
        return $q;
    }

    /** Consulta base de contratos: rango de fechas, alcance del usuario y filtros de estructura. */
    private function ejecucionQuery(array $filters): Builder
    {
        $q = $this->applyDateRange(ContratoEjecucion::query(), $filters);
        $this->scope->aplicarASaldos($q);

        if (!empty($filters['sector_id'])) {
            $q->whereIn('sector_id', $this->arbol->ramaDe((int) $filters['sector_id']) ?: [0]);
        }
        if (!empty($filters['gerencia_area_id'])) {
            $q->whereIn('sector_id', $this->arbol->ramaDe((int) $filters['gerencia_area_id']) ?: [0]);
        }

        return $q;
    }

    /** Suma de movimientos por tipo, respetando alcance y filtros. */
    private function sumaMovimientos(array $filters, ?string $tipo = null): float
    {
        $ids = $this->ejecucionQuery($filters)->select('contratos_ejecucion.id');

        return (float) DB::table('ejecucion_movimientos')
            ->whereNull('deleted_at')
            ->whereIn('contrato_ejecucion_id', $ids)
            ->when($tipo !== null, fn ($q) => $q->where('tipo', $tipo))
            ->sum('monto');
    }

    /** ---------------- Sección A: indicadores principales ---------------- */
    public function indicadoresPrincipales(array $filters): array
    {
        $hoy = Carbon::today();

        $total = $this->ejecucionQuery($filters)->count();

        $enFirma = $this->ejecucionQuery($filters)
            ->whereHas('estado', fn ($q) => $q->where('nombre', 'like', 'En firma%'))->count();
        $enEjec = $this->ejecucionQuery($filters)
            ->whereHas('estado', fn ($q) => $q->where('nombre', 'En ejecución'))->count();
        $finalizados = $this->ejecucionQuery($filters)
            ->whereHas('estado', fn ($q) => $q->where('nombre', 'Finalizado'))->count();

        $vencidos = $this->ejecucionQuery($filters)
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
            ->count();

        $monedaBase = $filters['moneda_base'] ?? 'Peso';
        $sumPresupIngresos = $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_ingresos', $monedaBase);
        $sumPresupGastos   = $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_gastos',   $monedaBase);
        $sumEjecIngresos   = $this->sumaMovimientos($filters, 'ingreso');
        $sumEjecGastos     = $this->sumaMovimientos($filters, 'gasto');

        return [
            'totales' => [
                'contratos'    => $total,
                'en_firma'     => $enFirma,
                'en_ejecucion' => $enEjec,
                'finalizados'  => $finalizados,
                'vencidos'     => $vencidos,
                'con_atraso'   => $vencidos,
            ],
            'montos' => [
                'moneda_base'                  => $monedaBase,
                'presupuestado_ingresos_total' => round($sumPresupIngresos, 2),
                'presupuestado_gastos_total'   => round($sumPresupGastos, 2),
                'ejecutado_ingresos_total'     => round($sumEjecIngresos, 2),
                'ejecutado_gastos_total'       => round($sumEjecGastos, 2),
                'beneficio_total'              => round($sumEjecIngresos - $sumEjecGastos, 2),
            ],
        ];
    }

    /** ---------------- Sección C: indicadores calculados ---------------- */
    public function indicadoresCalculados(array $filters): array
    {
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $diasFirma = $this->promedioDiasACambioEstado(
            campoFecha: 'fecha_apertura_expediente',
            estadoNombre: 'Firmado',
            filters: $filters,
        );

        $diasEjec = $this->promedioDiasEntreEstados(
            estadoInicial: 'En ejecución',
            estadoFinal: 'Finalizado',
            filters: $filters,
        );

        $sumPresup = $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_ingresos', $monedaBase)
                   + $this->sumarMontos($this->ejecucionQuery($filters), 'monto_presupuestado_gastos',   $monedaBase);
        $sumEjec  = $this->sumaMovimientos($filters);
        $pctEjec  = $sumPresup > 0 ? round(($sumEjec / $sumPresup) * 100, 2) : null;

        return [
            'dias_firma_promedio'             => $diasFirma,
            'dias_ejecucion_promedio'         => $diasEjec,
            'porcentaje_finalizados_en_termino' => $this->porcentajeFinalizadosEnTermino($filters),
            'porcentaje_vencidos_sin_cierre'  => $this->porcentajeVencidosSinCierre($filters),
            'porcentaje_ejecucion_economica'  => $pctEjec,
            'moneda_base'                     => $monedaBase,
        ];
    }

    /** ---------------- Saldos configurables ---------------- */

    /**
     * Saldos jerarquizados según lo que el usuario quiera ver: por Gerencia de
     * Área, abriendo además sus subsectores, o bajando hasta el contrato.
     *
     * Los importes de cada fila incluyen los de toda su rama: una Gerencia de
     * Área suma lo de todos sus subsectores. Así los totales cierran en
     * cualquier nivel al que se mire.
     */
    public function saldos(array $filters): array
    {
        $agrupacion = in_array($filters['agrupacion'] ?? null, self::AGRUPACIONES, true)
            ? $filters['agrupacion']
            : 'gerencia_area';
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $contratos = $this->ejecucionQuery($filters)
            ->select(
                'contratos_ejecucion.id',
                'contratos_ejecucion.nro_expediente',
                'contratos_ejecucion.nombre_proyecto',
                'contratos_ejecucion.sector_id',
                'contratos_ejecucion.moneda',
                'contratos_ejecucion.cotizacion',
                'contratos_ejecucion.monto_presupuestado_ingresos',
                'contratos_ejecucion.monto_presupuestado_gastos',
            )
            ->get();

        $sumas = $this->sumasPorContrato($contratos->pluck('id')->all());

        // 1) Importes propios de cada sector y, si hace falta, de cada contrato.
        $porSector   = [];
        $porContrato = [];
        foreach ($contratos as $c) {
            $sectorId = (int) $c->sector_id;
            $factor   = ($c->moneda === $monedaBase || !$c->cotizacion) ? 1.0 : (float) $c->cotizacion;

            $importes = [
                'contratos'              => 1,
                'presupuestado_ingresos' => ((float) ($c->monto_presupuestado_ingresos ?? 0)) * $factor,
                'presupuestado_gastos'   => ((float) ($c->monto_presupuestado_gastos   ?? 0)) * $factor,
                // Los movimientos ya están expresados en pesos.
                'ejecutado_ingresos'     => (float) ($sumas[$c->id]['ingreso'] ?? 0),
                'ejecutado_gastos'       => (float) ($sumas[$c->id]['gasto']   ?? 0),
            ];

            $porSector[$sectorId] = $this->acumular($porSector[$sectorId] ?? null, $importes);

            if ($agrupacion === 'contrato') {
                $porContrato[$sectorId][] = [
                    'clave'    => 'c-' . $c->id,
                    'tipo'     => 'contrato',
                    'id'       => (int) $c->id,
                    'etiqueta' => "#{$c->id} — {$c->nro_expediente}",
                    'detalle'  => $c->nombre_proyecto,
                ] + $importes;
            }
        }

        // 2) Cada sector acumula además lo de sus descendientes.
        $ramas = [];
        foreach (array_keys($porSector) as $sectorId) {
            $raiz = $this->arbol->raizDe($sectorId) ?? $sectorId;
            $ramas[$raiz] = true;
        }

        $filas = [];
        foreach (array_keys($ramas) as $raiz) {
            $this->emitirRama($raiz, 0, null, $agrupacion, $porSector, $porContrato, $filas, true);
        }

        $rows = collect($filas)->map(fn ($f) => $this->redondear($f))->values();

        // Los totales se toman sólo del nivel superior: sumar todos los niveles
        // contaría dos veces lo que ya está acumulado en la Gerencia de Área.
        $raices = $rows->where('nivel', 0);

        return [
            'agrupacion'  => $agrupacion,
            'moneda_base' => $monedaBase,
            'filas'       => $rows,
            'totales'     => [
                'contratos'              => (int) $raices->sum('contratos'),
                'presupuestado_ingresos' => round($raices->sum('presupuestado_ingresos'), 2),
                'presupuestado_gastos'   => round($raices->sum('presupuestado_gastos'), 2),
                'ejecutado_ingresos'     => round($raices->sum('ejecutado_ingresos'), 2),
                'ejecutado_gastos'       => round($raices->sum('ejecutado_gastos'), 2),
                'saldo'                  => round($raices->sum('saldo'), 2),
            ],
        ];
    }

    /**
     * Emite la fila de un sector con los importes de toda su rama y, según la
     * agrupación pedida, sigue bajando por sus subsectores y contratos.
     *
     * Con la agrupación por Gerencia de Área igual se recorre la rama completa,
     * pero sólo se emite la fila de la raíz: los subsectores se suman sin
     * mostrarse.
     *
     * @param  array<int, array<string, float|int>>        $porSector
     * @param  array<int, array<int, array<string, mixed>>> $porContrato
     * @param  array<int, array<string, mixed>>            $filas
     * @return array<string, float|int> importes acumulados de la rama
     */
    private function emitirRama(
        int $sectorId,
        int $nivel,
        ?string $padre,
        string $agrupacion,
        array $porSector,
        array $porContrato,
        array &$filas,
        bool $emitir,
    ): array {
        $clave    = 's-' . $sectorId;
        $esRaiz   = $this->arbol->raizDe($sectorId) === $sectorId;
        $posicion = null;

        if ($emitir) {
            // Se reserva el lugar de la fila: sus importes se completan después
            // de recorrer la rama, para que incluyan lo de los subsectores.
            $posicion = count($filas);
            $filas[$posicion] = [
                'clave'       => $clave,
                'tipo'        => $esRaiz ? 'gerencia_area' : 'subsector',
                'id'          => $sectorId,
                'etiqueta'    => $this->arbol->nombre($sectorId) ?? "Sector #{$sectorId}",
                'detalle'     => $esRaiz ? 'Gerencia de Área' : $this->arbol->nombre($this->arbol->padre($sectorId)),
                'nivel'       => $nivel,
                'padre_clave' => $padre,
            ];
        }

        $acumulado = $this->acumular(null, $porSector[$sectorId] ?? []);

        // Los subsectores sólo se muestran si la agrupación baja de nivel.
        $emitirHijos = $agrupacion !== 'gerencia_area';
        foreach ($this->arbol->hijosDe($sectorId) as $hijo) {
            $deHijo = $this->emitirRama(
                $hijo,
                $nivel + 1,
                $clave,
                $agrupacion,
                $porSector,
                $porContrato,
                $filas,
                $emitirHijos,
            );
            $acumulado = $this->acumular($acumulado, $deHijo);
        }

        if ($emitir && $agrupacion === 'contrato') {
            foreach ($porContrato[$sectorId] ?? [] as $contrato) {
                $filas[] = $contrato + ['nivel' => $nivel + 1, 'padre_clave' => $clave];
            }
        }

        if ($posicion !== null) {
            $filas[$posicion] += $acumulado;
        }

        return $acumulado;
    }

    /**
     * Suma dos juegos de importes.
     *
     * @param  array<string, float|int>|null  $base
     * @param  array<string, float|int>       $extra
     * @return array<string, float|int>
     */
    private function acumular(?array $base, array $extra): array
    {
        $campos = ['contratos', 'presupuestado_ingresos', 'presupuestado_gastos',
                   'ejecutado_ingresos', 'ejecutado_gastos'];

        $out = [];
        foreach ($campos as $campo) {
            $out[$campo] = ($base[$campo] ?? 0) + ($extra[$campo] ?? 0);
        }
        return $out;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function redondear(array $f): array
    {
        foreach (['presupuestado_ingresos', 'presupuestado_gastos',
                  'ejecutado_ingresos', 'ejecutado_gastos'] as $campo) {
            $f[$campo] = round((float) ($f[$campo] ?? 0), 2);
        }
        $f['contratos']           = (int) ($f['contratos'] ?? 0);
        $f['saldo']               = round($f['ejecutado_ingresos'] - $f['ejecutado_gastos'], 2);
        $f['disponible_ingresos'] = round($f['presupuestado_ingresos'] - $f['ejecutado_ingresos'], 2);
        $f['disponible_gastos']   = round($f['presupuestado_gastos'] - $f['ejecutado_gastos'], 2);
        return $f;
    }

    /**
     * Ejecutado por contrato y por tipo, en una sola consulta.
     *
     * @param  array<int>  $contratoIds
     * @return array<int, array{ingreso: float, gasto: float}>
     */
    private function sumasPorContrato(array $contratoIds): array
    {
        if (empty($contratoIds)) return [];

        $rows = DB::table('ejecucion_movimientos')
            ->whereNull('deleted_at')
            ->whereIn('contrato_ejecucion_id', $contratoIds)
            ->select('contrato_ejecucion_id', 'tipo', DB::raw('SUM(monto) as total'))
            ->groupBy('contrato_ejecucion_id', 'tipo')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->contrato_ejecucion_id][$r->tipo] = (float) $r->total;
        }
        return $out;
    }

    /** ---------------- Distribuciones ---------------- */
    public function porUvt(array $filters): array
    {
        return [
            'moneda_base' => $filters['moneda_base'] ?? 'Peso',
            'contratos'   => $this->ejecucionQuery($filters)
                ->select('uvt_id', DB::raw('COUNT(*) as cantidad'))
                ->groupBy('uvt_id')
                ->with('uvt:uvt_id,siglas,nombre')
                ->get()
                ->map(fn ($r) => [
                    'uvt_id'   => $r->uvt_id,
                    'siglas'   => optional($r->uvt)->siglas,
                    'nombre'   => optional($r->uvt)->nombre,
                    'cantidad' => (int) $r->cantidad,
                ]),
        ];
    }

    /**
     * Distribución de contratos por sector y por Gerencia de Área. La segunda
     * acumula la de todos los subsectores de cada rama.
     */
    public function porGerencia(array $filters): array
    {
        $porSector = $this->ejecucionQuery($filters)
            ->select('sector_id', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('sector_id')
            ->get();

        $porArea = [];
        $sectores = [];
        foreach ($porSector as $r) {
            $sectorId = (int) $r->sector_id;
            $cantidad = (int) $r->cantidad;
            $raiz     = $this->arbol->raizDe($sectorId) ?? $sectorId;

            $sectores[] = [
                'sector_id'     => $sectorId,
                'nombre'        => $this->arbol->nombre($sectorId) ?? "Sector #{$sectorId}",
                'gerencia_area' => $this->arbol->nombre($raiz),
                'cantidad'      => $cantidad,
            ];

            $porArea[$raiz] ??= [
                'gerencia_area_id' => $raiz,
                'nombre'           => $this->arbol->nombre($raiz) ?? "Sector #{$raiz}",
                'cantidad'         => 0,
            ];
            $porArea[$raiz]['cantidad'] += $cantidad;
        }

        return [
            'sectores'       => collect($sectores)->sortByDesc('cantidad')->values(),
            'gerencias_area' => collect(array_values($porArea))->sortByDesc('cantidad')->values(),
        ];
    }

    public function porTipo(array $filters): array
    {
        return [
            'contratos' => $this->ejecucionQuery($filters)
                ->select('tipo_contrato_id', DB::raw('COUNT(*) as cantidad'))
                ->groupBy('tipo_contrato_id')
                ->with('tipoContrato:id,sigla,nombre')
                ->get()
                ->map(fn ($r) => [
                    'tipo_contrato_id' => $r->tipo_contrato_id,
                    'sigla'            => optional($r->tipoContrato)->sigla,
                    'nombre'           => optional($r->tipoContrato)->nombre,
                    'cantidad'         => (int) $r->cantidad,
                ]),
        ];
    }

    public function porEstado(array $filters): array
    {
        return [
            'contratos' => $this->ejecucionQuery($filters)
                ->select('estado_id', DB::raw('COUNT(*) as cantidad'))
                ->groupBy('estado_id')
                ->with('estado:id,nombre')
                ->get()
                ->map(fn ($r) => [
                    'estado_id' => $r->estado_id,
                    'nombre'    => optional($r->estado)->nombre,
                    'cantidad'  => (int) $r->cantidad,
                ]),
        ];
    }

    public function porMoneda(array $filters): array
    {
        return [
            'contratos' => $this->ejecucionQuery($filters)
                ->select('moneda', DB::raw('COUNT(*) as cantidad'))
                ->groupBy('moneda')->get(),
        ];
    }

    /** Distribución de movimientos de ejecución por acción (factura, transferencia, incentivo, MCH). */
    public function porAccion(array $filters): array
    {
        $ids = $this->ejecucionQuery($filters)->select('contratos_ejecucion.id');

        $rows = DB::table('ejecucion_movimientos')
            ->whereNull('deleted_at')
            ->whereIn('contrato_ejecucion_id', $ids)
            ->select('accion', 'tipo', DB::raw('COUNT(*) as cantidad'), DB::raw('SUM(monto) as total'))
            ->groupBy('accion', 'tipo')
            ->get();

        return [
            'movimientos' => $rows->map(fn ($r) => [
                'accion'   => $r->accion,
                'tipo'     => $r->tipo,
                'cantidad' => (int) $r->cantidad,
                'total'    => round((float) $r->total, 2),
            ])->values(),
        ];
    }

    public function vencimientos(array $filters): array
    {
        $hoy = Carbon::today();

        $bucket = function (int $maxDias, ?int $minDias = null) use ($hoy, $filters) {
            return $this->ejecucionQuery($filters)
                ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
                ->whereDate('fecha_vencimiento', '>=', $hoy)
                ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays($maxDias))
                ->when($minDias !== null, fn ($q) => $q->whereDate('fecha_vencimiento', '>', $hoy->copy()->addDays($minDias)))
                ->count();
        };

        return [
            'vencidos' => $this->ejecucionQuery($filters)
                ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
                ->whereDate('fecha_vencimiento', '<', $hoy)->count(),
            'dias_30' => $bucket(30),
            'dias_60' => $bucket(60, 30),
            'dias_90' => $bucket(90, 60),
            'moneda_base' => $filters['moneda_base'] ?? 'Peso',
        ];
    }

    public function rankings(array $filters): array
    {
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $porArea = [];
        foreach ($this->ejecucionQuery($filters)
                    ->select('sector_id', DB::raw('COUNT(*) as cantidad'))
                    ->groupBy('sector_id')->get() as $r) {
            $raiz = $this->arbol->raizDe((int) $r->sector_id) ?? (int) $r->sector_id;
            $porArea[$raiz] ??= [
                'gerencia_area_id' => $raiz,
                'gerencia_area'    => $this->arbol->nombre($raiz) ?? "Sector #{$raiz}",
                'cantidad'         => 0,
            ];
            $porArea[$raiz]['cantidad'] += (int) $r->cantidad;
        }
        $rankGerencia = collect(array_values($porArea))->sortByDesc('cantidad')->take(20)->values();

        $rankUvtCantidad = $this->ejecucionQuery($filters)
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

        return [
            'gerencias_area_por_cantidad' => $rankGerencia,
            'uvt_por_cantidad'       => $rankUvtCantidad,
            'uvt_por_monto'          => $this->montoPorUvt($filters, $monedaBase),
            'moneda_base'            => $monedaBase,
        ];
    }

    /** ---------------- Helpers privados ---------------- */

    /**
     * Suma `monto * cotizacion` para llevar todo a la moneda base (Peso).
     *
     * Los importes negativos cuentan: los saldos iniciales de una gerencia
     * pueden serlo, y descartarlos haría que este total no coincida con el de
     * la vista de saldos.
     */
    private function sumarMontos(Builder $q, string $col, string $monedaBase): float
    {
        $rows = $q->select('moneda', 'cotizacion', $col)->get();
        $total = 0.0;
        foreach ($rows as $r) {
            $monto = (float) ($r->{$col} ?? 0);
            if ($monto === 0.0) continue;
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

    private function montoPorUvt(array $filters, string $monedaBase): Collection
    {
        $rows = $this->ejecucionQuery($filters)
            ->select('contratos_ejecucion.id', 'uvt_id', 'moneda', 'cotizacion',
                     'monto_presupuestado_ingresos', 'monto_presupuestado_gastos')
            ->with('uvt:uvt_id,siglas,nombre')
            ->get();

        $sumas = $this->sumasPorContrato($rows->pluck('id')->all());

        $byUvt = [];
        foreach ($rows as $r) {
            $key = $r->uvt_id ?? 0;
            $byUvt[$key] ??= [
                'uvt_id'        => $r->uvt_id,
                'siglas'        => optional($r->uvt)->siglas,
                'nombre'        => optional($r->uvt)->nombre,
                'presupuestado' => 0.0,
                'ejecutado'     => 0.0,
            ];
            $factor = ($r->moneda === $monedaBase || !$r->cotizacion) ? 1 : (float) $r->cotizacion;
            $byUvt[$key]['presupuestado'] += ((float) ($r->monto_presupuestado_ingresos ?? 0)
                                            + (float) ($r->monto_presupuestado_gastos ?? 0)) * $factor;
            // Ejecutados (movimientos) ya están en pesos: no aplica el factor de cotización del contrato.
            $byUvt[$key]['ejecutado'] += ($sumas[$r->id]['ingreso'] ?? 0) + ($sumas[$r->id]['gasto'] ?? 0);
        }

        return collect(array_values($byUvt))->map(function ($r) {
            $r['presupuestado'] = round($r['presupuestado'], 2);
            $r['ejecutado']     = round($r['ejecutado'], 2);
            return $r;
        })->sortByDesc('ejecutado')->values();
    }

    private function porcentajeFinalizadosEnTermino(array $filters): ?float
    {
        $finalizados = $this->ejecucionQuery($filters)
            ->whereHas('estado', fn ($e) => $e->where('nombre', 'Finalizado'))
            ->count();
        if ($finalizados === 0) return null;

        $enTermino = $this->ejecucionQuery($filters)
            ->whereHas('estado', fn ($e) => $e->where('nombre', 'Finalizado'))
            ->whereNotNull('fecha_finalizacion')
            ->whereNotNull('fecha_vencimiento')
            ->whereColumn('fecha_finalizacion', '<=', 'fecha_vencimiento')
            ->count();

        return round(($enTermino / $finalizados) * 100, 2);
    }

    private function porcentajeVencidosSinCierre(array $filters): ?float
    {
        $total = $this->ejecucionQuery($filters)->count();
        if ($total === 0) return null;

        $vencidos = $this->ejecucionQuery($filters)
            ->whereDate('fecha_vencimiento', '<', Carbon::today())
            ->whereHas('estado', fn ($q) => $q->where('nombre', '!=', 'Finalizado'))
            ->count();

        return round(($vencidos / $total) * 100, 2);
    }

    /**
     * Promedio de días entre el campoFecha del contrato y el primer cambio de
     * estado al estado indicado, según historial_cambios.
     */
    private function promedioDiasACambioEstado(string $campoFecha, string $estadoNombre, array $filters): ?float
    {
        $estadoId = DB::table('estado_ejecucion')->where('nombre', $estadoNombre)->value('id');
        if (!$estadoId) return null;

        $contratos = $this->ejecucionQuery($filters)
            ->whereNotNull($campoFecha)
            ->get(['contratos_ejecucion.id', $campoFecha]);

        $dias = [];
        foreach ($contratos as $c) {
            $h = $this->primerCambioAEstado((int) $c->id, (int) $estadoId);
            if (!$h) continue;
            $diff = Carbon::parse($c->{$campoFecha})->diffInDays(Carbon::parse($h->fecha));
            if ($diff >= 0) $dias[] = $diff;
        }

        return $dias ? round(array_sum($dias) / count($dias), 1) : null;
    }

    /** Promedio de días entre dos cambios de estado consecutivos en historial. */
    private function promedioDiasEntreEstados(string $estadoInicial, string $estadoFinal, array $filters): ?float
    {
        $idIni = DB::table('estado_ejecucion')->where('nombre', $estadoInicial)->value('id');
        $idFin = DB::table('estado_ejecucion')->where('nombre', $estadoFinal)->value('id');
        if (!$idIni || !$idFin) return null;

        $contratos = $this->ejecucionQuery($filters)->get(['contratos_ejecucion.id']);

        $dias = [];
        foreach ($contratos as $c) {
            $hIni = $this->primerCambioAEstado((int) $c->id, (int) $idIni);
            $hFin = $this->primerCambioAEstado((int) $c->id, (int) $idFin);
            if (!$hIni || !$hFin) continue;
            $diff = Carbon::parse($hIni->fecha)->diffInDays(Carbon::parse($hFin->fecha));
            if ($diff >= 0) $dias[] = $diff;
        }

        return $dias ? round(array_sum($dias) / count($dias), 1) : null;
    }

    private function primerCambioAEstado(int $contratoId, int $estadoId): ?HistorialCambio
    {
        return HistorialCambio::where('tabla', 'contratos_ejecucion')
            ->where('registro_id', $contratoId)
            ->where('campo_modificado', 'estado_id')
            ->where('valor_nuevo', (string) $estadoId)
            ->orderBy('fecha', 'asc')
            ->first();
    }
}
