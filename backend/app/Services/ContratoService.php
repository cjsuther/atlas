<?php

namespace App\Services;

use App\Models\Contrato;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ContratoService
{
    /**
     * Construye la query de contratos aplicando filtros.
     */
    public function buildQuery(array $filters): Builder
    {
        $q = Contrato::query()->with([
            'estado:estado_id,estado_nombre',
            'tipoContrato:id_tipo,tipo,nombre',
            'solicitante:solicitante_id,razon_social',
            'uvt:uvt_id,siglas,nombre',
            'sector:sector_id,nombre',
            'resp1:legajo,apellido,nombre',
            'resp2:legajo,apellido,nombre',
        ]);

        if (!empty($filters['estado_id'])) {
            $q->where('estado_id', (int) $filters['estado_id']);
        }
        if (!empty($filters['tipo_de_contrato_id'])) {
            $q->where('tipo_de_contrato_id', (int) $filters['tipo_de_contrato_id']);
        }
        if (!empty($filters['sector_id'])) {
            $q->where('sector_id', (int) $filters['sector_id']);
        }
        if (!empty($filters['solicitante_id'])) {
            $q->where('solicitante_id', (int) $filters['solicitante_id']);
        }
        if (!empty($filters['uvt_id'])) {
            $q->where('uvt_id', (int) $filters['uvt_id']);
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

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function (Builder $w) use ($term) {
                $w->where('nombre_proy', 'like', $term)
                  ->orWhere('expediente', 'like', $term)
                  ->orWhere('descripcion_objeto', 'like', $term);
            });
        }

        $orderBy  = $filters['order_by']  ?? 'id_cto';
        $orderDir = strtolower($filters['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedOrder = ['id_cto', 'nombre_proy', 'fecha_inicio', 'fecha_vencimiento',
                         'fecha_firma', 'estado_id', 'tipo_de_contrato_id', 'created_at'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'id_cto';
        }

        return $q->orderBy($orderBy, $orderDir);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 200));
        return $this->buildQuery($filters)->paginate($perPage);
    }

    public function find(int $id): ?Contrato
    {
        return Contrato::with([
            'estado', 'tipoContrato', 'solicitante', 'uvt', 'sector',
            'resp1', 'resp2', 'dependenciaContractual:id_cto,nombre_proy',
        ])->find($id);
    }

    public function create(array $data): Contrato
    {
        $contrato = new Contrato();
        $contrato->fill($data);
        $contrato->save();
        return $contrato->fresh();
    }

    public function update(int $id, array $data): ?Contrato
    {
        $c = Contrato::find($id);
        if (!$c) return null;
        $c->fill($data);
        $c->save();
        return $c->fresh();
    }

    /**
     * Baja lógica: estado → "Sin efecto" (estado_id = 4 según seed).
     */
    public function softDelete(int $id): ?Contrato
    {
        $c = Contrato::find($id);
        if (!$c) return null;
        $c->estado_id = 4;
        $c->save();
        return $c->fresh();
    }
}
