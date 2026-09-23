<?php

namespace App\Services;

use App\Models\Expediente;
use App\Models\HistorialCambio;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
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
 *   - nodo_id               : acota a un Contrato y su rama
 *   - cuenta_operativa_id   : acota a una sola cuenta
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
        $q = $this->applyDateRange(Expediente::query(), $filters);
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

    /**
     * Cuentas del alcance del usuario recortadas por los filtros de estructura.
     * La plata vive en las cuentas, así que todo importe del panel sale de acá.
     */
    private function cuentasQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('cuentas_operativas');

        $visibles = $this->scope->cuentasVisibles();
        if ($visibles !== null) {
            $q->whereIn('id', $visibles ?: [0]);
        }

        foreach (['gerencia_area_id', 'sector_id', 'nodo_id'] as $filtro) {
            if (!empty($filters[$filtro])) {
                $q->whereIn('sector_id', $this->arbol->ramaDe((int) $filters[$filtro]) ?: [0]);
            }
        }
        if (!empty($filters['cuenta_operativa_id'])) {
            $q->where('id', (int) $filters['cuenta_operativa_id']);
        }

        return $q;
    }

    /** Movimientos registrados en esas cuentas, dentro del rango de fechas. */
    private function movimientosQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('ejecucion_movimientos')
            ->whereNull('deleted_at')
            ->whereIn('cuenta_operativa_id', $this->cuentasQuery($filters)->select('id'));

        if (!empty($filters['desde'])) $q->whereDate('created_at', '>=', $filters['desde']);
        if (!empty($filters['hasta'])) $q->whereDate('created_at', '<=', $filters['hasta']);

        return $q;
    }

    /**
     * Nodos «Contrato» —el tercer nivel de la estructura— que entran en el
     * alcance del usuario y en los filtros. Es lo que se cuenta cuando se habla
     * de contratos; los registros que se imputan a una cuenta son expedientes.
     *
     * @return array<int>
     */
    private function contratosDelAlcance(array $filters): array
    {
        $visibles = $this->scope->sectoresVisibles();

        $ramas = [];
        foreach (['gerencia_area_id', 'sector_id', 'nodo_id'] as $filtro) {
            if (!empty($filters[$filtro])) {
                $ramas[] = $this->arbol->ramaDe((int) $filters[$filtro]);
            }
        }

        // Filtrar por una cuenta deja sólo el nodo del que cuelga.
        if (!empty($filters['cuenta_operativa_id'])) {
            $sector = DB::table('cuentas_operativas')
                ->where('id', (int) $filters['cuenta_operativa_id'])->value('sector_id');
            $ramas[] = $sector !== null ? [(int) $sector] : [];
        }

        $ids = [];
        foreach (DB::table('sector')->pluck('sector_id') as $sectorId) {
            $sectorId = (int) $sectorId;
            if ($this->arbol->nivelDe($sectorId) !== 'contrato') {
                continue;
            }
            if ($visibles !== null && !in_array($sectorId, $visibles, true)) {
                continue;
            }
            foreach ($ramas as $rama) {
                if (!in_array($sectorId, $rama, true)) {
                    continue 2;
                }
            }
            $ids[] = $sectorId;
        }

        return $ids;
    }

    /** Suma de movimientos por tipo, respetando alcance y filtros. */
    private function sumaMovimientos(array $filters, ?string $tipo = null): float
    {
        return (float) $this->movimientosQuery($filters)
            ->when($tipo !== null, fn ($q) => $q->where('tipo', $tipo))
            ->sum('monto');
    }

    /** ---------------- Sección A: indicadores principales ---------------- */
    public function indicadoresPrincipales(array $filters): array
    {
        $cuentas   = (int) $this->cuentasQuery($filters)->count();
        $contratos = count($this->contratosDelAlcance($filters));

        $monedaBase = $filters['moneda_base'] ?? 'Peso';
        $sumSaldoInicial = (float) $this->cuentasQuery($filters)->sum('saldo_inicial');
        $sumEjecIngresos = $this->sumaMovimientos($filters, 'ingreso');
        $sumEjecGastos   = $this->sumaMovimientos($filters, 'gasto');

        return [
            'totales' => [
                'cuentas'   => $cuentas,
                'contratos' => $contratos,
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

    /** ---------------- Saldos configurables ---------------- */

    /**
     * Saldos del árbol de la estructura, hasta el nivel que el usuario quiera
     * ver: Gerencia de Área, Gerencia o Contrato.
     *
     * Los importes salen de las cuentas de cada nodo: su saldo inicial más lo
     * que entró y salió por sus movimientos. Se cuentan también sus cuentas y
     * los nodos «Contrato» —el tercer nivel— que cuelgan de él.
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

        // 1) Importes propios de cada nodo: lo de sus cuentas.
        $porSector = [];

        foreach ($this->cuentasQuery($filters)->get(['id', 'sector_id', 'saldo_inicial']) as $cuenta) {
            $sectorId = (int) $cuenta->sector_id;
            $porSector[$sectorId] = $this->acumular($porSector[$sectorId] ?? null, [
                'cuentas'       => 1,
                'saldo_inicial' => (float) ($cuenta->saldo_inicial ?? 0),
            ]);
        }

        // Los movimientos ya están expresados en pesos.
        $movimientos = $this->movimientosQuery($filters)
            ->join('cuentas_operativas as co', 'co.id', '=', 'ejecucion_movimientos.cuenta_operativa_id')
            ->select('co.sector_id', 'ejecucion_movimientos.tipo', DB::raw('SUM(monto) as total'))
            ->groupBy('co.sector_id', 'ejecucion_movimientos.tipo')
            ->get();

        foreach ($movimientos as $m) {
            $sectorId = (int) $m->sector_id;
            $porSector[$sectorId] = $this->acumular($porSector[$sectorId] ?? null, [
                'ejecutado_ingresos' => $m->tipo === 'ingreso' ? (float) $m->total : 0,
                'ejecutado_gastos'   => $m->tipo === 'gasto'   ? (float) $m->total : 0,
            ]);
        }

        // Los contratos son los nodos del tercer nivel: cada uno cuenta por sí
        // mismo y suma hacia arriba al acumulado de su rama.
        foreach ($this->contratosDelAlcance($filters) as $sectorId) {
            $porSector[$sectorId] = $this->acumular($porSector[$sectorId] ?? null, [
                'contratos' => 1,
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
                'cuentas'            => (int) $raices->sum('cuentas'),
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
            $vacia = (int) $acumulado['contratos'] === 0 && (int) $acumulado['cuentas'] === 0;

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
        $campos = ['cuentas', 'contratos', 'cantidad', 'saldo_inicial', 'ejecutado_ingresos', 'ejecutado_gastos'];

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
        $f['cuentas']   = (int) ($f['cuentas'] ?? 0);
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
            ->whereIn('expediente_id', $contratoIds)
            ->select('expediente_id', 'tipo', DB::raw('SUM(monto) as total'))
            ->groupBy('expediente_id', 'tipo')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->expediente_id][$r->tipo] = (float) $r->total;
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
            ->select('expedientes.id', 'expedientes.sector_id',
                     'expedientes.uvt_id', 'expedientes.moneda',
                     'expedientes.cotizacion', 'expedientes.saldo_inicial')
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


    /**
     * Distribución por Gerencia y por Gerencia de Área. Los importes salen de
     * las cuentas, que es donde está la plata: lo de una cuenta suma a la
     * Gerencia de la que cuelga —o al propio nodo, si cuelga de una Gerencia de
     * Área— y la Gerencia de Área acumula toda su rama.
     */
    public function porGerencia(array $filters): array
    {
        $porSector = [];
        $porArea   = [];

        $acumularEn = function (array &$destino, int|string $clave, array $base, array $importes) {
            $destino[$clave] ??= $base;
            $destino[$clave] = $this->acumular($destino[$clave], $importes) + $destino[$clave];
        };

        foreach ($this->importesPorCuenta($filters) as $c) {
            $raiz     = $this->arbol->raizDe($c['sector_id']) ?? $c['sector_id'];
            // Lo de la propia Gerencia de Área no tiene Gerencia: queda en su nodo.
            $sectorId = $this->arbol->ancestrosPorNivel($c['sector_id'])['gerencia'] ?? $c['sector_id'];

            $acumularEn($porSector, $sectorId, [
                'sector_id'     => $sectorId,
                'nombre'        => $this->arbol->nombre($sectorId) ?? "Gerencia #{$sectorId}",
                'gerencia_area' => $this->arbol->nombre($raiz),
            ], $c['importes']);

            $acumularEn($porArea, $raiz, [
                'gerencia_area_id' => $raiz,
                'nombre'           => $this->arbol->nombre($raiz) ?? "Gerencia #{$raiz}",
            ], $c['importes']);
        }

        return [
            'moneda_base'    => $filters['moneda_base'] ?? 'Peso',
            'sectores'       => collect(array_values($porSector))
                ->map(fn ($r) => $this->cerrarFila($r))->sortByDesc('saldo')->values(),
            'gerencias_area' => collect(array_values($porArea))
                ->map(fn ($r) => $this->cerrarFila($r))->sortByDesc('saldo')->values(),
        ];
    }

    /**
     * Importes de cada cuenta del alcance, listos para agrupar: su saldo
     * inicial y lo que entró y salió por sus movimientos.
     *
     * @return array<int, array{sector_id: int, importes: array<string, float|int>}>
     */
    private function importesPorCuenta(array $filters): array
    {
        $movimientos = [];
        foreach (
            $this->movimientosQuery($filters)
                ->select('cuenta_operativa_id', 'tipo', DB::raw('SUM(monto) as total'))
                ->groupBy('cuenta_operativa_id', 'tipo')
                ->get() as $m
        ) {
            $movimientos[(int) $m->cuenta_operativa_id][$m->tipo] = (float) $m->total;
        }

        $out = [];
        foreach ($this->cuentasQuery($filters)->get(['id', 'sector_id', 'saldo_inicial']) as $cuenta) {
            $id = (int) $cuenta->id;
            $out[] = [
                'sector_id' => (int) $cuenta->sector_id,
                'importes'  => [
                    'cantidad'           => 1,
                    'saldo_inicial'      => (float) ($cuenta->saldo_inicial ?? 0),
                    'ejecutado_ingresos' => $movimientos[$id]['ingreso'] ?? 0,
                    'ejecutado_gastos'   => $movimientos[$id]['gasto']   ?? 0,
                ],
            ];
        }

        return $out;
    }

    /** Distribución de movimientos de ejecución por acción (factura, transferencia, incentivo, MCH). */
    public function porAccion(array $filters): array
    {
        $rows = $this->movimientosQuery($filters)
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



    /** ---------------- Helpers privados ---------------- */






    private function primerCambioAEstado(int $contratoId, int $estadoId): ?HistorialCambio
    {
        return HistorialCambio::where('tabla', 'expedientes')
            ->where('registro_id', $contratoId)
            ->where('campo_modificado', 'estado_id')
            ->where('valor_nuevo', (string) $estadoId)
            ->orderBy('fecha', 'asc')
            ->first();
    }
}
