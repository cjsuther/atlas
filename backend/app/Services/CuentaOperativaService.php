<?php

namespace App\Services;

use App\Models\Expediente;
use App\Models\CuentaOperativa;
use App\Models\EjecucionMovimiento;
use Illuminate\Support\Facades\DB;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CuentaOperativaService extends BaseCrudService
{
    protected string $modelClass = CuentaOperativa::class;
    // Calificados: al ordenar por ubicación se suma la tabla de la estructura,
    // que también tiene `nombre`.
    protected array $searchableFields = ['cuentas_operativas.nombre', 'cuentas_operativas.descripcion'];

    public function __construct(
        protected AccessScopeService $scope,
        protected SectorTree $arbol,
    ) {
    }

    /**
     * Las cuentas se ven sólo dentro de la rama del usuario: son el corazón de
     * la operatoria y la Gerencia de Área es el límite de confidencialidad.
     * Un permiso sobre la raíz no recorta nada.
     */
    protected function baseQuery(): Builder
    {
        $visibles = $this->scope->sectoresVisibles();

        return CuentaOperativa::query()
            ->when($visibles !== null, fn ($q) => $q->whereIn('cuentas_operativas.sector_id', $visibles ?: [0]))
            ->with(['sector:sector_id,nombre,dependencia_id', 'responsable:legajo,apellido,nombre'])
            ->addSelect([
                'cuentas_operativas.*',
                'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
                'sum_gastos'   => $this->sumMovimientosSub('gasto'),
            ]);
    }

    /**
     * Las archivadas no se listan salvo que se pidan. La Gerencia de Área y la
     * Gerencia alcanzan a toda su rama: las cuentas del nodo y las de abajo.
     */
    protected function aplicarFiltros(Builder $query, array $params): void
    {
        if (empty($params['archivadas'])) {
            $query->where('cuentas_operativas.activo', true);
        }
        foreach (['gerencia_area_id', 'gerencia_id', 'contrato_id'] as $filtro) {
            if (!empty($params[$filtro])) {
                $query->whereIn('cuentas_operativas.sector_id',
                    $this->arbol->ramaDe((int) $params[$filtro]) ?: [0]);
            }
        }
    }

    /**
     * Además del nombre y la descripción, se busca en lo que la grilla muestra:
     * la ubicación en la estructura —una cuenta aparece al buscar su Gerencia—
     * y el responsable.
     */
    protected function aplicarBusqueda(Builder $query, string $term): void
    {
        $ramas = [];
        foreach (DB::table('sector')->where('nombre', 'like', $term)->pluck('sector_id') as $id) {
            array_push($ramas, ...$this->arbol->ramaDe((int) $id));
        }

        $query->where(function (Builder $q) use ($term, $ramas) {
            foreach ($this->searchableFields as $field) {
                $q->orWhere($field, 'like', $term);
            }
            if ($ramas) {
                $q->orWhereIn('cuentas_operativas.sector_id', array_unique($ramas));
            }
            $q->orWhereHas('responsable', fn ($p) => $p
                ->where('apellido', 'like', $term)
                ->orWhere('nombre', 'like', $term)
                ->orWhereRaw("CONCAT(apellido, ', ', nombre) LIKE ?", [$term])
                ->orWhereRaw("CONCAT(nombre, ' ', apellido) LIKE ?", [$term]));
        });
    }

    /**
     * Archivada, la cuenta deja de verse en listados y selectores; su saldo y
     * sus movimientos siguen en la base y en el panel.
     */
    public function archivar(int $id, bool $archivar): ?Model
    {
        $cuenta = $this->find($id);
        if (!$cuenta) {
            return null;
        }
        CuentaOperativa::whereKey($id)->update(['activo' => !$archivar]);
        return $this->find($id);
    }

    /** Suma de los movimientos de la cuenta, para no consultarlos uno por uno. */
    private function sumMovimientosSub(string $tipo)
    {
        return DB::table('ejecucion_movimientos')
            ->whereColumn('ejecucion_movimientos.cuenta_operativa_id', 'cuentas_operativas.id')
            ->whereNull('deleted_at')
            ->where('estado_aprobacion', EjecucionMovimiento::APROBADO)
            ->where('tipo', $tipo)
            ->selectRaw('COALESCE(SUM(monto), 0)');
    }

    /**
     * El saldo no es una columna: sale del saldo inicial más los movimientos.
     * MySQL admite los alias del SELECT dentro del ORDER BY.
     */
    protected function aplicarOrdenPropio(Builder $query, string $campo, string $dir): bool
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        $expresiones = [
            'saldo'    => '(cuentas_operativas.saldo_inicial + sum_ingresos - sum_gastos)',
            'ingresos' => 'sum_ingresos',
            'gastos'   => 'sum_gastos',
        ];

        // El responsable se ordena por su apellido, que es como se lo muestra.
        if ($campo === 'responsable_id') {
            $query->orderBy(
                DB::table('personal')
                    ->selectRaw("CONCAT(apellido, ', ', nombre)")
                    ->whereColumn('personal.legajo', 'cuentas_operativas.responsable_id')
                    ->limit(1),
                $dir
            );
            return true;
        }

        if (isset($expresiones[$campo])) {
            $query->orderByRaw($expresiones[$campo] . ' ' . $dir);
            return true;
        }

        // El nivel y la ubicación salen del nodo del que cuelga la cuenta y de
        // sus ancestros, que es como se arma la ruta que se muestra.
        if (in_array($campo, ['nivel', 'ruta'], true)) {
            $query->leftJoin('sector as nodo', 'nodo.sector_id', '=', 'cuentas_operativas.sector_id')
                  ->leftJoin('sector as padre', 'padre.sector_id', '=', 'nodo.dependencia_id')
                  ->leftJoin('sector as abuelo', 'abuelo.sector_id', '=', 'padre.dependencia_id');

            if ($campo === 'ruta') {
                $query->orderByRaw("CONCAT_WS(' › ', abuelo.nombre, padre.nombre, nodo.nombre) " . $dir);
            } else {
                $query->orderByRaw('(CASE WHEN nodo.dependencia_id IS NULL THEN 1
                                          WHEN padre.dependencia_id IS NULL THEN 2
                                          ELSE 3 END) ' . $dir);
            }
            return true;
        }

        return false;
    }

    public function dependenciesFor(int|string $id): array
    {
        return $this->dependenciasDe(
            Expediente::withTrashed()->where('cuenta_operativa_id', $id),
            EjecucionMovimiento::withTrashed()->where('cuenta_operativa_id', $id),
            'esta cuenta',
        );
    }

    /**
     * Expedientes y movimientos que impiden borrar una cuenta o un nodo.
     *
     * Cuentan también los dados de baja: siguen en la base como historial y la
     * clave foránea no deja borrar lo que los referencia. Se los nombra aparte
     * porque no aparecen en los listados ni suman al saldo.
     *
     * @param  Builder  $expedientes  consulta con withTrashed()
     * @param  Builder  $movimientos  consulta con withTrashed()
     * @return array<string>
     */
    public function dependenciasDe(Builder $expedientes, Builder $movimientos, string $donde): array
    {
        $msgs = [];
        foreach ([
            [$expedientes, 'expediente(s) imputado(s) a', 'dado(s) de baja'],
            [$movimientos, 'movimiento(s) registrado(s) en', 'eliminado(s)'],
        ] as [$query, $texto, $baja]) {
            $total = (clone $query)->count();
            if ($total === 0) {
                continue;
            }
            $bajas = (clone $query)->onlyTrashed()->count();
            $msgs[] = "Existen {$total} {$texto} {$donde}"
                . ($bajas > 0 ? " ({$bajas} {$baja}, que se conservan en el historial)." : '.');
        }
        return $msgs;
    }

    /**
     * Mover una cuenta de nodo mueve de rama a todos sus expedientes: la rama
     * que llevan guardada se deduce de la cuenta, así que se recalcula.
     */
    public function update(int|string $id, array $data): ?Model
    {
        $cuenta = parent::update($id, $data);
        if ($cuenta) {
            Expediente::where('cuenta_operativa_id', $cuenta->id)
                ->update(['sector_id' => $cuenta->sector_id]);
        }
        return $cuenta;
    }

    /**
     * Cuenta homónima de un nodo del árbol. Es la que se crea junto con el nodo
     * para que nunca haya una rama sin cuenta a la que imputar.
     */
    public function crearParaSector(int $sectorId, string $nombre): CuentaOperativa
    {
        return CuentaOperativa::firstOrCreate(
            ['sector_id' => $sectorId, 'nombre' => $nombre],
            // Arranca con el responsable del nodo, si lo tiene.
            ['activo' => true,
             'responsable_id' => DB::table('sector')->where('sector_id', $sectorId)->value('responsable_id')],
        );
    }

    /**
     * El árbol de la estructura con las cuentas de cada nodo, para los
     * selectores: al imputar un expediente se elige una cuenta de cualquier
     * rama, y quien asigna permisos elige un nodo.
     *
     * La raíz es toda la organización; de ella cuelgan las Gerencias de Área.
     *
     * El árbol se recorta a la rama del usuario: sólo aparecen los nodos que
     * puede ver y los que hacen falta para llegar a ellos, y de cada uno, sus
     * cuentas visibles. Cada cuenta viene marcada con `permitida`, que es
     * escritura: se ve la cuenta pero no siempre se puede imputar a ella.
     * Quién decide todo esto es el servidor, no la pantalla.
     */
    public function arbolConCuentas(SectorTree $arbol): array
    {
        $scope       = app(AccessScopeService::class);
        $visibles    = $scope->sectoresVisibles();
        $permitidas  = $scope->cuentasVisibles(soloEscritura: true);
        $deLectura   = $scope->cuentasVisibles();

        $cuentas = CuentaOperativa::where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'sector_id'])
            ->groupBy(fn ($c) => $c->sector_id === null ? 'raiz' : (string) $c->sector_id);

        // Un nodo entra si el usuario lo ve o si cuelga de él algo que ve.
        $alcanzado = function (int $sectorId) use (&$alcanzado, $arbol, $visibles): bool {
            if ($visibles === null || in_array($sectorId, $visibles, true)) {
                return true;
            }
            foreach ($arbol->hijosDe($sectorId) as $hijo) {
                if ($alcanzado($hijo)) {
                    return true;
                }
            }
            return false;
        };

        // De la raíz cuelgan las Gerencias de Área o, si el usuario tiene un
        // alcance acotado, los nodos más altos que puede ver.
        $topes = $visibles === null
            ? $arbol->raices()
            : array_values(array_unique(array_map(fn ($id) => $scope->topeVisible($id), $visibles)));

        $nodo = function (?int $sectorId) use (&$nodo, $arbol, $cuentas, $permitidas, $deLectura, $alcanzado, $topes): array {
            $clave = $sectorId === null ? 'raiz' : (string) $sectorId;
            $hijos = $sectorId === null ? $topes : $arbol->hijosDe($sectorId);
            $hijos = array_values(array_filter($hijos, $alcanzado));

            return [
                'sector_id' => $sectorId,
                'nombre'    => $sectorId === null
                    ? SectorTree::ETIQUETAS[SectorTree::NIVEL_ORGANIZACION]
                    : $arbol->nombre($sectorId),
                'nivel'     => $arbol->nivelDe($sectorId),
                'cuentas'   => ($cuentas[$clave] ?? collect())
                    ->filter(fn ($c) => $deLectura === null || in_array((int) $c->id, $deLectura, true))
                    ->map(fn ($c) => [
                        'id'        => $c->id,
                        'nombre'    => $c->nombre,
                        'permitida' => $permitidas === null || in_array((int) $c->id, $permitidas, true),
                    ])->values()->all(),
                'hijos'     => array_map($nodo, $hijos),
            ];
        };

        return $nodo(null);
    }
}
