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
    protected array $searchableFields = ['nombre', 'descripcion'];

    protected function baseQuery(): Builder
    {
        return CuentaOperativa::query()
            ->with('sector:sector_id,nombre,dependencia_id')
            ->addSelect([
                'cuentas_operativas.*',
                'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
                'sum_gastos'   => $this->sumMovimientosSub('gasto'),
            ]);
    }

    /** Suma de los movimientos de la cuenta, para no consultarlos uno por uno. */
    private function sumMovimientosSub(string $tipo)
    {
        return DB::table('ejecucion_movimientos')
            ->whereColumn('ejecucion_movimientos.cuenta_operativa_id', 'cuentas_operativas.id')
            ->whereNull('deleted_at')
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
        $msgs = [];
        $expedientes = Expediente::where('cuenta_operativa_id', $id)->count();
        if ($expedientes > 0) {
            $msgs[] = "Existen {$expedientes} expediente(s) imputado(s) a esta cuenta.";
        }
        $movimientos = EjecucionMovimiento::where('cuenta_operativa_id', $id)->count();
        if ($movimientos > 0) {
            $msgs[] = "Existen {$movimientos} movimiento(s) registrado(s) en esta cuenta.";
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
            ['activo' => true],
        );
    }

    /**
     * El árbol de la estructura con las cuentas de cada nodo, para los
     * selectores: al imputar un expediente se elige una cuenta de cualquier
     * rama, y quien asigna permisos elige un nodo.
     *
     * La raíz es toda la organización; de ella cuelgan las Gerencias de Área.
     *
     * Cada cuenta viene marcada con `permitida`: el árbol se muestra entero,
     * pero sólo se puede imputar a las cuentas de la rama del usuario. Quién
     * decide eso es el servidor, no la pantalla.
     */
    public function arbolConCuentas(SectorTree $arbol): array
    {
        $permitidas = app(AccessScopeService::class)->cuentasVisibles();

        $cuentas = CuentaOperativa::where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'sector_id'])
            ->groupBy(fn ($c) => $c->sector_id === null ? 'raiz' : (string) $c->sector_id);

        $nodo = function (?int $sectorId) use (&$nodo, $arbol, $cuentas, $permitidas): array {
            $clave = $sectorId === null ? 'raiz' : (string) $sectorId;
            $hijos = $sectorId === null ? $arbol->raices() : $arbol->hijosDe($sectorId);

            return [
                'sector_id' => $sectorId,
                'nombre'    => $sectorId === null
                    ? SectorTree::ETIQUETAS[SectorTree::NIVEL_ORGANIZACION]
                    : $arbol->nombre($sectorId),
                'nivel'     => $arbol->nivelDe($sectorId),
                'cuentas'   => ($cuentas[$clave] ?? collect())
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
