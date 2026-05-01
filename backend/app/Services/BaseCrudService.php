<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Servicio CRUD genérico reutilizado por las entidades maestras.
 * Cada subclase indica el modelo y los campos buscables.
 */
abstract class BaseCrudService
{
    /** @var class-string<Model> */
    protected string $modelClass;

    /** @var array<string> */
    protected array $searchableFields = [];

    public function paginate(array $params = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if (!empty($params['search']) && !empty($this->searchableFields)) {
            $term = '%' . $params['search'] . '%';
            $query->where(function (Builder $q) use ($term) {
                foreach ($this->searchableFields as $field) {
                    $q->orWhere($field, 'like', $term);
                }
            });
        }

        $orderBy = $params['order_by'] ?? null;
        $orderDir = strtolower($params['order_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        if ($orderBy && $this->isOrderable($orderBy)) {
            $query->orderBy($orderBy, $orderDir);
        } else {
            $query->orderBy($this->getKeyName(), 'asc');
        }

        $perPage = max(1, min((int) ($params['per_page'] ?? $perPage), 200));
        return $query->paginate($perPage);
    }

    public function find(int|string $id): ?Model
    {
        return $this->baseQuery()->find($id);
    }

    public function create(array $data): Model
    {
        $model = new ($this->modelClass)();
        $model->fill($data);
        $model->save();
        return $model->fresh();
    }

    public function update(int|string $id, array $data): ?Model
    {
        $model = $this->baseQuery()->find($id);
        if (!$model) return null;
        $model->fill($data);
        $model->save();
        return $model->fresh();
    }

    public function delete(int|string $id): bool
    {
        $model = $this->baseQuery()->find($id);
        if (!$model) return false;
        return (bool) $model->delete();
    }

    /**
     * Si la entidad tiene dependencias en otras tablas, este método debe sobreescribirse
     * para devolver un array de mensajes de validación. Si no está vacío, no se elimina.
     *
     * @return array<string>
     */
    public function dependenciesFor(int|string $id): array
    {
        return [];
    }

    protected function baseQuery(): Builder
    {
        return ($this->modelClass)::query();
    }

    protected function getKeyName(): string
    {
        return (new $this->modelClass)->getKeyName();
    }

    protected function isOrderable(string $field): bool
    {
        // Por defecto permitir cualquier columna fillable o la PK; las subclases pueden
        // restringir con whitelist si lo necesitan.
        $model = new $this->modelClass;
        return in_array($field, array_merge($model->getFillable(), [$model->getKeyName()]), true);
    }
}
