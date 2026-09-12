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
 *   - desde, hasta          : recortan por created_at del expediente
 *   - moneda_base           : 'Peso' por defecto, para conversión de montos
 *   - gerencia_area_id      : acota a una Gerencia de Área y su rama
 *   - sector_id             : acota a una Gerencia y su rama
 *   - nodo_id               : acota a un Contrato (tercer nivel) y su rama
 *   - cuenta_operativa_id   : acota a los expedientes de una cuenta
 *
 * Los de estructura se combinan: cada uno recorta sobre el anterior.
 */
class PanelService
{
    /** Agrupaciones admitidas para la vista de saldos. */
    public const AGRUPACIONES = ['gerencia_area', 'gerencia', 'contrato'];

    /** Hasta qué profundidad del árbol se abre cada agrupación. */
    private const PROFUNDIDAD = ['gerencia_area' => 1, 'gerencia' => 2, 'contrato' => 3];

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
        if (!empty($filters['nodo_id'])) {
            $q->whereIn('sector_id', $this->arbol->ramaDe((int) $filters['nodo_id']) ?: [0]);
        }
        // La cuenta es el corte más fino: los expedientes imputados a ella.
        if (!empty($filters['cuenta_operativa_id'])) {
            $q->where('cuenta_operativa_id', (int) $filters['cuenta_operativa_id']);
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
        $sumSaldoInicial = $this->sumarMontos($this->ejecucionQuery($filters), 'saldo_inicial', $monedaBase);
        $sumEjecIngresos = $this->sumaMovimientos($filters, 'ingreso');
        $sumEjecGastos   = $this->sumaMovimientos($filters, 'gasto');

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
                'moneda_base'              => $monedaBase,
                'saldo_inicial_total'      => round($sumSaldoInicial, 2),
                'ejecutado_ingresos_total' => round($sumEjecIngresos, 2),
                'ejecutado_gastos_total'   => round($sumEjecGastos, 2),
                'beneficio_total'          => round($sumEjecIngresos - $sumEjecGastos, 2),
                'saldo_total'              => round($sumSaldoInicial + $sumEjecIngresos - $sumEjecGastos, 2),
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

        $sumSaldoInicial = $this->sumarMontos($this->ejecucionQuery($filters), 'saldo_inicial', $monedaBase);
        $sumEjec = $this->sumaMovimientos($filters);
        $pctEjec = $sumSaldoInicial > 0 ? round(($sumEjec / $sumSaldoInicial) * 100, 2) : null;

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
     * Saldos del árbol de la estructura, hasta el nivel que el usuario quiera
     * ver: Gerencia de Área, Gerencia o Contrato.
     *
     * Cada nodo aporta dos filas:
     *
     *   acumulado  : todo lo de la rama, incluido lo que cuelga del nodo.
     *   propios    : sólo los expedientes imputados a las cuentas del nodo.
     *
     * Las dos hacen falta porque un expediente puede imputarse a una cuenta de
     * cualquier nivel: sin la fila de propios no se ve qué carga tiene el nodo
     * en sí, y sin la de acumulado no cierra el total de la rama.
     *
     * El saldo es `saldo inicial + ingresos ejecutados - gastos ejecutados`.
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
                'contratos_ejecucion.sector_id',
                'contratos_ejecucion.moneda',
                'contratos_ejecucion.cotizacion',
                'contratos_ejecucion.saldo_inicial',
            )
            ->get();

        $sumas = $this->sumasPorContrato($contratos->pluck('id')->all());

        // 1) Importes propios de cada nodo: lo imputado a sus cuentas.
        $porSector = [];
        foreach ($contratos as $c) {
            $sectorId = (int) $c->sector_id;
            $factor   = ($c->moneda === $monedaBase || !$c->cotizacion) ? 1.0 : (float) $c->cotizacion;

            $porSector[$sectorId] = $this->acumular($porSector[$sectorId] ?? null, [
                'contratos'          => 1,
                'saldo_inicial'      => ((float) ($c->saldo_inicial ?? 0)) * $factor,
                // Los movimientos ya están expresados en pesos.
                'ejecutado_ingresos' => (float) ($sumas[$c->id]['ingreso'] ?? 0),
                'ejecutado_gastos'   => (float) ($sumas[$c->id]['gasto']   ?? 0),
            ]);
        }

        // 2) Se recorre cada rama emitiendo las dos filas de cada nodo.
        $ramas = [];
        foreach (array_keys($porSector) as $sectorId) {
            $raiz = $this->arbol->raizDe($sectorId) ?? $sectorId;
            $ramas[$raiz] = true;
        }

        $filas = [];
        foreach (array_keys($ramas) as $raiz) {
            $this->emitirRama($raiz, 0, null, self::PROFUNDIDAD[$agrupacion], $porSector, $filas);
        }

        $rows = collect($filas)
            ->reject(fn ($f) => $f['descartar'] ?? false)
            ->map(function ($f) {
                unset($f['descartar']);
                return $this->redondear($f);
            })
            ->values();

        // Los totales salen de las filas acumuladas del nivel superior: sumar
        // todos los niveles contaría dos veces lo que ya está acumulado arriba.
        $raices = $rows->where('nivel', 0)->where('alcance', 'acumulado');

        return [
            'agrupacion'  => $agrupacion,
            'moneda_base' => $monedaBase,
            'filas'       => $rows,
            'totales'     => [
                'contratos'          => (int) $raices->sum('contratos'),
                'saldo_inicial'      => round($raices->sum('saldo_inicial'), 2),
                'ejecutado_ingresos' => round($raices->sum('ejecutado_ingresos'), 2),
                'ejecutado_gastos'   => round($raices->sum('ejecutado_gastos'), 2),
                'saldo'              => round($raices->sum('saldo'), 2),
            ],
        ];
    }

    /**
     * Emite las filas de un nodo —primero el acumulado de la rama y debajo lo
     * propio— y sigue bajando por sus hijos mientras la agrupación lo permita.
     *
     * La rama se recorre entera aunque no se muestre: un nodo que no se emite
     * igual aporta sus importes al acumulado de su padre.
     *
     * Un nodo sin nada en toda su rama no se emite: la tabla mostraría dos
     * filas en cero por cada uno.
     *
     * @param  array<int, array<string, float|int>> $porSector
     * @param  array<int, array<string, mixed>>     $filas
     * @return array<string, float|int> importes acumulados de la rama
     */
    private function emitirRama(
        int $sectorId,
        int $nivel,
        ?string $padre,
        int $profundidad,
        array $porSector,
        array &$filas,
    ): array {
        $clave  = 's-' . $sectorId;
        $emitir = $nivel < $profundidad;

        // Se reservan los dos lugares antes de recorrer la rama: el acumulado
        // se completa recién cuando volvieron todos los hijos.
        $posAcumulado = $posPropios = null;
        if ($emitir) {
            $base = [
                'tipo'        => $this->arbol->nivelDe($sectorId),
                'id'          => $sectorId,
                'etiqueta'    => $this->arbol->nombre($sectorId) ?? "Nodo #{$sectorId}",
                'nivel'       => $nivel,
                'padre_clave' => $padre,
            ];

            $posAcumulado = count($filas);
            $filas[$posAcumulado] = $base + [
                'clave'   => $clave . '-acumulado',
                'alcance' => 'acumulado',
                'detalle' => 'Incluye lo que depende de él',
            ];

            $posPropios = count($filas);
            $filas[$posPropios] = $base + [
                'clave'   => $clave . '-propios',
                'alcance' => 'propios',
                'detalle' => 'Imputado a sus cuentas',
            ];
        }

        $propios   = $this->acumular(null, $porSector[$sectorId] ?? []);
        $acumulado = $propios;

        foreach ($this->arbol->hijosDe($sectorId) as $hijo) {
            $deHijo = $this->emitirRama($hijo, $nivel + 1, $clave, $profundidad, $porSector, $filas);
            $acumulado = $this->acumular($acumulado, $deHijo);
        }

        if ($posAcumulado !== null) {
            // Una rama vacía se marca en lugar de borrarse: las posiciones ya
            // reservadas se siguen usando mientras se recorre el resto.
            $vacia = (int) $acumulado['contratos'] === 0;

            $filas[$posPropios]   += $propios   + ['descartar' => $vacia];
            $filas[$posAcumulado] += $acumulado + ['descartar' => $vacia];
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
        $campos = ['contratos', 'cantidad', 'saldo_inicial', 'ejecutado_ingresos', 'ejecutado_gastos'];

        $out = [];
        foreach ($campos as $campo) {
            $out[$campo] = ($base[$campo] ?? 0) + ($extra[$campo] ?? 0);
        }
        return $out;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function redondear(array $f): array
    {
        foreach (['saldo_inicial', 'ejecutado_ingresos', 'ejecutado_gastos'] as $campo) {
            $f[$campo] = round((float) ($f[$campo] ?? 0), 2);
        }
        $f['contratos'] = (int) ($f['contratos'] ?? 0);
        // Saldo = lo que había al empezar, más lo que entró, menos lo que salió.
        $f['saldo'] = round(
            $f['saldo_inicial'] + $f['ejecutado_ingresos'] - $f['ejecutado_gastos'], 2
        );
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

    /**
     * Importes de cada contrato del alcance, listos para agrupar.
     *
     * @return array<int, array{sector_id: int, uvt_id: ?int, importes: array<string, float|int>}>
     */
    private function importesPorContrato(array $filters): array
    {
        $monedaBase = $filters['moneda_base'] ?? 'Peso';

        $contratos = $this->ejecucionQuery($filters)
            ->select('contratos_ejecucion.id', 'contratos_ejecucion.sector_id',
                     'contratos_ejecucion.uvt_id', 'contratos_ejecucion.moneda',
                     'contratos_ejecucion.cotizacion', 'contratos_ejecucion.saldo_inicial')
            ->get();

        $sumas = $this->sumasPorContrato($contratos->pluck('id')->all());

        $out = [];
        foreach ($contratos as $c) {
            $factor = ($c->moneda === $monedaBase || !$c->cotizacion) ? 1.0 : (float) $c->cotizacion;
            $out[] = [
                'sector_id' => (int) $c->sector_id,
                'uvt_id'    => $c->uvt_id !== null ? (int) $c->uvt_id : null,
                'importes'  => [
                    'cantidad'           => 1,
                    'saldo_inicial'      => ((float) ($c->saldo_inicial ?? 0)) * $factor,
                    'ejecutado_ingresos' => (float) ($sumas[$c->id]['ingreso'] ?? 0),
                    'ejecutado_gastos'   => (float) ($sumas[$c->id]['gasto']   ?? 0),
                ],
            ];
        }
        return $out;
    }

    /**
     * Cierra una fila agrupada: redondea y calcula el saldo.
     *
     * @param  array<string, float|int>  $f
     * @return array<string, float|int>
     */
    private function cerrarFila(array $f): array
    {
        foreach (['saldo_inicial', 'ejecutado_ingresos', 'ejecutado_gastos'] as $campo) {
            $f[$campo] = round((float) ($f[$campo] ?? 0), 2);
        }
        $f['cantidad'] = (int) ($f['cantidad'] ?? 0);
        $f['saldo'] = round(
            $f['saldo_inicial'] + $f['ejecutado_ingresos'] - $f['ejecutado_gastos'], 2
        );
        return $f;
    }

    /** Distribución por UVT, con cantidad de contratos e importes. */
    public function porUvt(array $filters): array
    {
        $porUvt  = [];
        $nombres = DB::table('uvt')->get()->keyBy('uvt_id');

        foreach ($this->importesPorContrato($filters) as $c) {
            $clave = $c['uvt_id'] ?? 0;
            $porUvt[$clave] ??= [
                'uvt_id' => $c['uvt_id'],
                'siglas' => optional($nombres->get($c['uvt_id']))->siglas ?? 'Sin UVT',
                'nombre' => optional($nombres->get($c['uvt_id']))->nombre,
            ];
            $porUvt[$clave] = $this->acumular($porUvt[$clave], $c['importes']) + $porUvt[$clave];
        }

        return [
            'moneda_base' => $filters['moneda_base'] ?? 'Peso',
            'contratos'   => collect(array_values($porUvt))
                ->map(fn ($r) => $this->cerrarFila($r))
                ->sortByDesc('saldo')->values(),
        ];
    }

    /**
     * Distribución por sector y por Gerencia de Área, con cantidad de contratos
     * e importes. La de Gerencia de Área acumula la de todos sus subsectores.
     */
    public function porGerencia(array $filters): array
    {
        $porSector = [];
        $porArea   = [];

        foreach ($this->importesPorContrato($filters) as $c) {
            $sectorId = $c['sector_id'];
            $raiz     = $this->arbol->raizDe($sectorId) ?? $sectorId;

            $porSector[$sectorId] ??= [
                'sector_id'     => $sectorId,
                'nombre'        => $this->arbol->nombre($sectorId) ?? "Sector #{$sectorId}",
                'gerencia_area' => $this->arbol->nombre($raiz),
            ];
            $porSector[$sectorId] = $this->acumular($porSector[$sectorId], $c['importes']) + $porSector[$sectorId];

            $porArea[$raiz] ??= [
                'gerencia_area_id' => $raiz,
                'nombre'           => $this->arbol->nombre($raiz) ?? "Sector #{$raiz}",
            ];
            $porArea[$raiz] = $this->acumular($porArea[$raiz], $c['importes']) + $porArea[$raiz];
        }

        return [
            'moneda_base'    => $filters['moneda_base'] ?? 'Peso',
            'sectores'       => collect(array_values($porSector))
                ->map(fn ($r) => $this->cerrarFila($r))->sortByDesc('saldo')->values(),
            'gerencias_area' => collect(array_values($porArea))
                ->map(fn ($r) => $this->cerrarFila($r))->sortByDesc('saldo')->values(),
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

        return [
            'gerencias_area_por_cantidad' => $rankGerencia,
            'uvt_por_monto'          => $this->montoPorUvt($filters, $monedaBase)
                                            ->sortByDesc('saldo')->values(),
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
        return collect($this->porUvt($filters + ['moneda_base' => $monedaBase])['contratos']);
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
