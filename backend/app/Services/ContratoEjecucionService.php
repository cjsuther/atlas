<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\HistorialCambio;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContratoEjecucionService
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
            ->whereColumn('contrato_ejecucion_id', 'contratos_ejecucion.id')
            ->where('tipo', $tipo)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(monto), 0)');
    }

    public function buildQuery(array $filters): Builder
    {
        $q = ContratoEjecucion::query()
        ->select('contratos_ejecucion.*')
        ->with([
            'estado:id,nombre',
            'tipoContrato:id,sigla,nombre',
            'sector:sector_id,nombre,dependencia_id',
            'sector.dependencia:sector_id,nombre',
            'solicitante:solicitante_id,razon_social',
            'uvt:uvt_id,siglas,nombre',
            'resp1:legajo,apellido,nombre',
            'resp2:legajo,apellido,nombre',
        ])
        ->addSelect([
            'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
            'sum_gastos'   => $this->sumMovimientosSub('gasto'),
        ]);

        // Recorte obligatorio: nadie ve contratos fuera de su gerencia.
        $this->scope->aplicarAContratos($q);

        if (!empty($filters['estado_id'])) {
            $q->where('estado_id', (int) $filters['estado_id']);
        }
        if (!empty($filters['tipo_contrato_id'])) {
            $q->where('tipo_contrato_id', (int) $filters['tipo_contrato_id']);
        }
        if (!empty($filters['sector_id'])) {
            $q->where('sector_id', (int) $filters['sector_id']);
        }
        // Filtrar por Gerencia de Área alcanza a todos sus subsectores.
        if (!empty($filters['gerencia_area_id'])) {
            $rama = $this->arbol->ramaDe((int) $filters['gerencia_area_id']);
            $q->whereIn('sector_id', $rama ?: [0]);
        }
        if (!empty($filters['uvt_id'])) {
            $q->where('uvt_id', (int) $filters['uvt_id']);
        }
        if (!empty($filters['solicitante_id'])) {
            $q->where('solicitante_id', (int) $filters['solicitante_id']);
        }
        if (!empty($filters['moneda'])) {
            $q->where('moneda', $filters['moneda']);
        }

        if (!empty($filters['fecha_inicio_desde'])) {
            $q->whereDate('fecha_inicio', '>=', $filters['fecha_inicio_desde']);
        }
        if (!empty($filters['fecha_inicio_hasta'])) {
            $q->whereDate('fecha_inicio', '<=', $filters['fecha_inicio_hasta']);
        }
        if (!empty($filters['fecha_vencimiento_desde'])) {
            $q->whereDate('fecha_vencimiento', '>=', $filters['fecha_vencimiento_desde']);
        }
        if (!empty($filters['fecha_vencimiento_hasta'])) {
            $q->whereDate('fecha_vencimiento', '<=', $filters['fecha_vencimiento_hasta']);
        }

        if (!empty($filters['vencidos']) || !empty($filters['con_atraso'])) {
            $hoy = Carbon::today()->toDateString();
            $q->whereDate('fecha_vencimiento', '<', $hoy)
              ->whereHas('estado', fn ($e) => $e->where('nombre', '!=', 'Finalizado'));
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function (Builder $w) use ($term) {
                $w->where('nombre_proyecto', 'like', $term)
                  ->orWhere('nro_expediente', 'like', $term)
                  ->orWhere('descripcion_objeto', 'like', $term);
            });
        }

        if (!empty($filters['mostrar_baja'])) {
            $q->withTrashed();
        }

        return $this->aplicarOrden($q, $filters);
    }

    /** Columnas propias de la tabla por las que se puede ordenar. */
    private const ORDEN_COLUMNAS = [
        'id', 'nro_expediente', 'nombre_proyecto', 'fecha_apertura_expediente',
        'fecha_inicio', 'fecha_vencimiento', 'fecha_finalizacion',
        'saldo_inicial', 'created_at',
    ];

    /**
     * Órdenes que apuntan a otra tabla. Se ordena por el nombre de la entidad,
     * no por su id, que es lo que el usuario ve en la grilla.
     *
     * clave => [tabla, clave primaria, clave foránea, campo a ordenar]
     */
    private const ORDEN_RELACIONES = [
        'estado' => ['estado_ejecucion',        'id',        'estado_id',        'nombre'],
        'tipo'   => ['tipo_contrato_ejecucion', 'id',        'tipo_contrato_id', 'sigla'],
        'sector' => ['sector',                  'sector_id', 'sector_id',        'nombre'],
        'uvt'    => ['uvt',                     'uvt_id',    'uvt_id',           'siglas'],
    ];

    /**
     * Ordena el listado. Además de las columnas propias admite ordenar por el
     * nombre de las entidades relacionadas y por el saldo, que es un calculado.
     */
    private function aplicarOrden(Builder $q, array $filters): Builder
    {
        $dir = strtolower($filters['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $por = $filters['order_by'] ?? 'id';

        if (in_array($por, self::ORDEN_COLUMNAS, true)) {
            return $q->orderBy("contratos_ejecucion.{$por}", $dir);
        }

        if (isset(self::ORDEN_RELACIONES[$por])) {
            [$tabla, $pk, $fk, $campo] = self::ORDEN_RELACIONES[$por];
            return $q->orderBy(
                DB::table($tabla)
                    ->select($campo)
                    ->whereColumn("{$tabla}.{$pk}", "contratos_ejecucion.{$fk}")
                    ->limit(1),
                $dir
            );
        }

        if ($por === 'saldo') {
            // Mismo cálculo que expone el contrato: inicial + ingresos - gastos.
            return $q->orderByRaw(
                'COALESCE(contratos_ejecucion.saldo_inicial, 0)'
                . ' + ' . $this->sumaMovimientosSql('ingreso')
                . ' - ' . $this->sumaMovimientosSql('gasto')
                . ' ' . $dir
            );
        }

        return $q->orderBy('contratos_ejecucion.id', 'desc');
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
                 WHERE m.contrato_ejecucion_id = contratos_ejecucion.id
                   AND m.tipo = '{$tipo}' AND m.deleted_at IS NULL), 0)";
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 200));
        return $this->buildQuery($filters)->paginate($perPage);
    }

    public function find(int $id, bool $withTrashed = false): ?ContratoEjecucion
    {
        $q = ContratoEjecucion::query()
            ->select('contratos_ejecucion.*')
            ->with([
                'estado', 'tipoContrato', 'sector.dependencia', 'solicitante', 'uvt',
                'resp1', 'resp2',
            ])
            ->addSelect([
                'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
                'sum_gastos'   => $this->sumMovimientosSub('gasto'),
            ]);
        if ($withTrashed) $q->withTrashed();

        $this->scope->aplicarAContratos($q);

        return $q->find($id);
    }

    public function create(array $data): ContratoEjecucion
    {
        $c = new ContratoEjecucion();
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    public function update(int $id, array $data): ?ContratoEjecucion
    {
        $c = $this->findEditable($id);
        if (!$c) return null;
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    public function softDelete(int $id): bool
    {
        $c = $this->findEditable($id);
        if (!$c) return false;
        return (bool) $c->delete();
    }

    /**
     * Transfiere el contrato completo a otro sector. Los movimientos de
     * ejecución acompañan al contrato, porque cuelgan de él.
     *
     * Sólo el administrador de sistema puede hacerlo: la transferencia puede
     * cruzar el límite de la Gerencia de Área.
     */
    public function transferirASector(int $id, int $sectorId, ?string $motivo = null): ?ContratoEjecucion
    {
        $c = ContratoEjecucion::find($id);
        if (!$c) return null;

        if ((int) $c->sector_id === $sectorId) {
            return $c;
        }

        DB::transaction(function () use ($c, $sectorId, $motivo) {
            // El observer de historial ya registra el cambio de sector_id; el
            // motivo se guarda como una entrada adicional para dejarlo asentado.
            $c->sector_id = $sectorId;
            $c->save();

            if ($motivo) {
                HistorialCambio::create([
                    'tabla'            => $c->getTable(),
                    'registro_id'      => (int) $c->getKey(),
                    'tipo_cambio'      => 'modificacion',
                    'campo_modificado' => 'transferencia_motivo',
                    'valor_anterior'   => null,
                    'valor_nuevo'      => $motivo,
                    'usuario'          => Auth::user()?->username ?? 'system',
                ]);
            }
        });

        return $c->fresh();
    }

    /** Busca el contrato exigiendo que esté dentro del alcance del usuario. */
    private function findEditable(int $id): ?ContratoEjecucion
    {
        $c = ContratoEjecucion::find($id);
        if (!$c) return null;
        return $this->scope->puedeEditarContrato($c) ? $c : null;
    }
}
