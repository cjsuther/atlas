<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContratoEjecucionService
{
    /**
     * Campos heredados desde el contrato principal cuando se crea/vincula
     * un contrato de ejecución sin que el usuario los haya completado.
     */
    public const HERITABLE_FIELDS = [
        'gerencia_area', 'gerencia',
        'solicitante_id', 'resp1_id', 'resp2_id',
        'utt_id',
    ];

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
            'principal:id,nro_expediente,nombre_proyecto',
            'solicitante:solicitante_id,razon_social',
            'uvt:uvt_id,siglas,nombre',
            'utt:utt_id,denominacion,nombre,regimen',
            'resp1:legajo,apellido,nombre',
            'resp2:legajo,apellido,nombre',
        ])
        ->addSelect([
            'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
            'sum_gastos'   => $this->sumMovimientosSub('gasto'),
        ]);

        if (!empty($filters['estado_id'])) {
            $q->where('estado_id', (int) $filters['estado_id']);
        }
        if (!empty($filters['tipo_contrato_id'])) {
            $q->where('tipo_contrato_id', (int) $filters['tipo_contrato_id']);
        }
        if (!empty($filters['contrato_principal_id'])) {
            $q->where('contrato_principal_id', (int) $filters['contrato_principal_id']);
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

    public function find(int $id, bool $withTrashed = false): ?ContratoEjecucion
    {
        $q = ContratoEjecucion::query()
            ->select('contratos_ejecucion.*')
            ->with([
                'estado', 'tipoContrato', 'principal', 'solicitante', 'uvt', 'utt',
                'resp1', 'resp2',
            ])
            ->addSelect([
                'sum_ingresos' => $this->sumMovimientosSub('ingreso'),
                'sum_gastos'   => $this->sumMovimientosSub('gasto'),
            ]);
        if ($withTrashed) $q->withTrashed();
        return $q->find($id);
    }

    public function create(array $data): ContratoEjecucion
    {
        $data = $this->applyHeritage($data);
        $c = new ContratoEjecucion();
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    public function update(int $id, array $data): ?ContratoEjecucion
    {
        $c = ContratoEjecucion::find($id);
        if (!$c) return null;
        $data = $this->applyHeritage($data);
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    public function softDelete(int $id): bool
    {
        $c = ContratoEjecucion::find($id);
        if (!$c) return false;
        return (bool) $c->delete();
    }

    /**
     * Si hay contrato_principal_id y el campo heredable no fue enviado
     * (o llegó vacío), lo completa con el valor del principal.
     * Si el usuario lo envió con valor, respeta su elección.
     */
    private function applyHeritage(array $data): array
    {
        $pid = $data['contrato_principal_id'] ?? null;
        if (!$pid) return $data;

        $principal = ContratoPrincipal::find($pid);
        if (!$principal) return $data;

        foreach (self::HERITABLE_FIELDS as $f) {
            $sentExplicit = array_key_exists($f, $data)
                            && $data[$f] !== null
                            && $data[$f] !== '';
            if (!$sentExplicit) {
                $data[$f] = $principal->{$f};
            }
        }
        return $data;
    }
}
