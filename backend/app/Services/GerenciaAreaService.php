<?php

namespace App\Services;

use App\Models\Gerencia;
use App\Models\GerenciaArea;
use Illuminate\Database\Eloquent\Builder;

class GerenciaAreaService extends BaseCrudService
{
    protected string $modelClass = GerenciaArea::class;
    protected array $searchableFields = ['nombre', 'sigla', 'responsable'];

    public function __construct(protected AccessScopeService $scope)
    {
    }

    /** Un usuario acotado sólo ve la Gerencia de Área a la que pertenece. */
    protected function baseQuery(): Builder
    {
        $q = GerenciaArea::query()->withCount('gerencias');

        $areas = $this->scope->gerenciasArea();
        if ($areas !== null) {
            $q->whereIn('id', $areas ?: [0]);
        }
        return $q;
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $gerencias = Gerencia::where('gerencia_area_id', $id)->count();
        if ($gerencias > 0) {
            $msgs[] = "Existen {$gerencias} gerencia(s) en esta Gerencia de Área.";
        }
        return $msgs;
    }
}
