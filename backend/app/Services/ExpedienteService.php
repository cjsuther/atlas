<?php

namespace App\Services;

use App\Models\Expediente;
use App\Models\CuentaOperativa;
use App\Models\HistorialCambio;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExpedienteService
{
    public function __construct(
        protected AccessScopeService $scope,
        protected SectorTree $arbol,
    ) {
    }

    /** Subquery SQL para sumar movimientos por tipo en esta ejecución. */
    private function sumMovimientosSub(string $tipo)
    {
        return DB::table('ejecucion_movimientos')
            ->whereColumn('expediente_id', 'expedientes.id')
            ->where('tipo', $tipo)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(monto), 0)');
    }

    public function buildQuery(array $filters): Builder
    {
        $q = Expediente::query()
        ->select('expedientes.*')
        ->with([
            'sector:sector_id,nombre,dependencia_id',
            'sector.dependencia:sector_id,nombre',
            'cuentaOperativa:id,nombre,sector_id',
        ])
        ->addSelect([
            'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
            'sum_gastos'   => $this->sumMovimientosSub('gasto'),
        ]);

        // Recorte obligatorio: nadie ve expedientes fuera de su rama.
        $this->scope->aplicarAContratos($q);

        // Cada filtro de la estructura alcanza a toda la rama del nodo elegido,
        // y se combinan: cada uno recorta sobre el anterior.
        foreach (['gerencia_area_id', 'sector_id', 'nodo_id'] as $filtro) {
            if (!empty($filters[$filtro])) {
                $q->whereIn('sector_id', $this->arbol->ramaDe((int) $filters[$filtro]) ?: [0]);
            }
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where('nro_expediente', 'like', $term);
        }

        if (!empty($filters['cuenta_operativa_id'])) {
            $q->where('cuenta_operativa_id', (int) $filters['cuenta_operativa_id']);
        }

        if (!empty($filters['mostrar_baja'])) {
            $q->withTrashed();
        }

        return $this->aplicarOrden($q, $filters);
    }

    /** Columnas propias de la tabla por las que se puede ordenar. */
    private const ORDEN_COLUMNAS = ['id', 'nro_expediente', 'created_at'];

    /**
     * Órdenes que apuntan a otra tabla. Se ordena por el nombre de la entidad,
     * no por su id, que es lo que el usuario ve en la grilla.
     *
     * clave => [tabla, clave primaria, clave foránea, campo a ordenar]
     */
    private const ORDEN_RELACIONES = [
        'sector' => ['sector',             'sector_id', 'sector_id',           'nombre'],
        'cuenta' => ['cuentas_operativas', 'id',        'cuenta_operativa_id', 'nombre'],
    ];

    /**
     * Ordena el listado. Además de las columnas propias admite ordenar por el
     * nombre de la rama y de la cuenta, y por el resultado, que es calculado.
     */
    private function aplicarOrden(Builder $q, array $filters): Builder
    {
        $dir = strtolower($filters['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $por = $filters['order_by'] ?? 'id';

        if (in_array($por, self::ORDEN_COLUMNAS, true)) {
            return $q->orderBy("expedientes.{$por}", $dir);
        }

        if (isset(self::ORDEN_RELACIONES[$por])) {
            [$tabla, $pk, $fk, $campo] = self::ORDEN_RELACIONES[$por];
            return $q->orderBy(
                DB::table($tabla)
                    ->select($campo)
                    ->whereColumn("{$tabla}.{$pk}", "expedientes.{$fk}")
                    ->limit(1),
                $dir
            );
        }

        if ($por === 'saldo') {
            // Resultado de lo relacionado: ingresos menos gastos.
            return $q->orderByRaw(
                $this->sumaMovimientosSql('ingreso')
                . ' - ' . $this->sumaMovimientosSql('gasto')
                . ' ' . $dir
            );
        }

        return $q->orderBy('expedientes.id', 'desc');
    }

    /**
     * Subconsulta de movimientos como SQL, para poder usarla en un ORDER BY.
     * El tipo se resuelve contra una lista cerrada: nunca llega texto externo
     * a la consulta.
     */
    private function sumaMovimientosSql(string $tipo): string
    {
        $tipo = match ($tipo) {
            'ingreso' => 'ingreso',
            'gasto'   => 'gasto',
        };

        return "COALESCE((SELECT SUM(m.monto) FROM ejecucion_movimientos m
                 WHERE m.expediente_id = expedientes.id
                   AND m.tipo = '{$tipo}' AND m.deleted_at IS NULL), 0)";
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 200));
        return $this->buildQuery($filters)->paginate($perPage);
    }

    public function find(int $id, bool $withTrashed = false): ?Expediente
    {
        $q = Expediente::query()
            ->select('expedientes.*')
            ->with(['sector.dependencia', 'cuentaOperativa'])
            ->addSelect([
                'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
                'sum_gastos'   => $this->sumMovimientosSub('gasto'),
            ]);
        if ($withTrashed) $q->withTrashed();

        $this->scope->aplicarAContratos($q);

        return $q->find($id);
    }

    public function create(array $data): Expediente
    {
        $c = new Expediente();
        $c->fill($this->conRamaDerivada($data));
        $c->save();
        return $c->fresh();
    }

    public function update(int $id, array $data): ?Expediente
    {
        $c = $this->findEditable($id);
        if (!$c) return null;
        $c->fill($this->conRamaDerivada($data));
        $c->save();
        return $c->fresh();
    }

    /**
     * La rama del expediente no se carga: sale del nodo de su cuenta operativa.
     * Se guarda igual en `sector_id` porque el alcance, el panel y las consultas
     * filtran por ahí; la cuenta sigue siendo la única fuente de verdad.
     */
    private function conRamaDerivada(array $data): array
    {
        if (!array_key_exists('cuenta_operativa_id', $data)) {
            return $data;
        }

        $cuenta = CuentaOperativa::find($data['cuenta_operativa_id']);
        $data['sector_id'] = $cuenta?->sector_id;

        return $data;
    }

    public function softDelete(int $id): bool
    {
        $c = $this->findEditable($id);
        if (!$c) return false;
        return (bool) $c->delete();
    }


    /** Busca el contrato exigiendo que esté dentro del alcance del usuario. */
    private function findEditable(int $id): ?Expediente
    {
        $c = Expediente::find($id);
        if (!$c) return null;
        return $this->scope->puedeEditarContrato($c) ? $c : null;
    }
}
