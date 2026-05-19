<?php

namespace App\Services;

use App\Models\ContratoPrincipal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContratoPrincipalService
{
    /**
     * Subquery SQL para sumar movimientos por tipo a través de las
     * ejecuciones del principal. Devuelve un Builder listo para usar
     * con addSelect(['sum_ejec_<tipo>' => $sub]).
     */
    private function sumMovimientosSub(string $tipo)
    {
        return DB::table('ejecucion_movimientos as m')
            ->join('contratos_ejecucion as ce', 'ce.id', '=', 'm.contrato_ejecucion_id')
            ->whereColumn('ce.contrato_principal_id', 'contratos_principal.id')
            ->where('m.tipo', $tipo)
            ->whereNull('m.deleted_at')
            ->whereNull('ce.deleted_at')
            ->selectRaw('COALESCE(SUM(m.monto), 0)');
    }

    /** Construye la query de contratos principales aplicando filtros. */
    public function buildQuery(array $filters): Builder
    {
        $q = ContratoPrincipal::query()
        ->select('contratos_principal.*')
        ->with([
            'estado:id,nombre',
            'tipoContrato:id,sigla,nombre',
            'solicitante:solicitante_id,razon_social',
            'uvt:uvt_id,siglas,nombre',
            'utt:utt_id,denominacion,nombre,regimen',
            'resp1:legajo,apellido,nombre',
            'resp2:legajo,apellido,nombre',
        ])
        ->withCount('ejecuciones')
        ->addSelect([
            'sum_ejec_ingresos' => $this->sumMovimientosSub('ingreso'),
            'sum_ejec_gastos'   => $this->sumMovimientosSub('gasto'),
        ]);

        if (!empty($filters['estado_id'])) {
            $q->where('estado_id', (int) $filters['estado_id']);
        }
        if (!empty($filters['tipo_contrato_id'])) {
            $q->where('tipo_contrato_id', (int) $filters['tipo_contrato_id']);
        }
        if (!empty($filters['uvt_id'])) {
            $q->where('uvt_id', (int) $filters['uvt_id']);
        }
        if (!empty($filters['utt_id'])) {
            $q->where('utt_id', (int) $filters['utt_id']);
        }
        if (!empty($filters['solicitante_id'])) {
            $q->where('solicitante_id', (int) $filters['solicitante_id']);
        }
        if (!empty($filters['gerencia'])) {
            $q->where('gerencia', 'like', '%' . $filters['gerencia'] . '%');
        }
        if (!empty($filters['moneda'])) {
            $q->where('moneda', $filters['moneda']);
        }
        if (!empty($filters['regimen'])) {
            $q->where('regimen', $filters['regimen']);
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

        if (!empty($filters['vencidos'])) {
            $hoy = Carbon::today()->toDateString();
            $q->whereDate('fecha_vencimiento', '<', $hoy)
              ->whereHas('estado', fn ($e) => $e->where('nombre', '!=', 'Finalizado'));
        }
        if (!empty($filters['con_atraso'])) {
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

        $orderBy  = $filters['order_by']  ?? 'id';
        $orderDir = strtolower($filters['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $allowed  = ['id','nombre_proyecto','nro_expediente','fecha_inicio','fecha_vencimiento',
                     'fecha_finalizacion','estado_id','tipo_contrato_id','created_at'];
        if (!in_array($orderBy, $allowed, true)) $orderBy = 'id';

        return $q->orderBy($orderBy, $orderDir);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 200));
        return $this->buildQuery($filters)->paginate($perPage);
    }

    public function find(int $id, bool $withTrashed = false): ?ContratoPrincipal
    {
        $q = ContratoPrincipal::query()
        ->select('contratos_principal.*')
        ->with([
            'estado', 'tipoContrato', 'solicitante', 'uvt', 'utt',
            'resp1', 'resp2',
            'ejecuciones' => fn ($e) => $e->with(['estado:id,nombre', 'tipoContrato:id,sigla,nombre']),
        ])
        ->addSelect([
            'sum_ejec_ingresos' => $this->sumMovimientosSub('ingreso'),
            'sum_ejec_gastos'   => $this->sumMovimientosSub('gasto'),
        ]);
        if ($withTrashed) $q->withTrashed();
        return $q->find($id);
    }

    public function create(array $data): ContratoPrincipal
    {
        $c = new ContratoPrincipal();
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    public function update(int $id, array $data): ?ContratoPrincipal
    {
        $c = ContratoPrincipal::find($id);
        if (!$c) return null;
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    /** Baja lógica vía SoftDeletes. */
    public function softDelete(int $id): bool
    {
        $c = ContratoPrincipal::find($id);
        if (!$c) return false;
        return (bool) $c->delete();
    }
}
