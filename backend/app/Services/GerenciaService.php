<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\Gerencia;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Builder;

class GerenciaService extends BaseCrudService
{
    protected string $modelClass = Gerencia::class;
    protected array $searchableFields = ['nombre', 'sigla', 'responsable'];

    public function __construct(protected AccessScopeService $scope)
    {
    }

    /**
     * Se listan las gerencias del alcance de saldos del usuario: el
     * administrador de gerencia necesita ver las de su Gerencia de Área para
     * poder agrupar saldos, aunque sólo opere contratos en la suya.
     */
    protected function baseQuery(): Builder
    {
        $q = Gerencia::query()
            ->with('gerenciaArea:id,sigla,nombre')
            ->withCount('contratos');

        $ids = $this->scope->gerenciasDeSaldos();
        if ($ids !== null) {
            $q->whereIn('id', $ids ?: [0]);
        }
        return $q;
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];

        $contratos = ContratoEjecucion::withTrashed()->where('gerencia_id', $id)->count();
        if ($contratos > 0) {
            $msgs[] = "Existen {$contratos} contrato(s) en esta gerencia.";
        }

        $usuarios = UserRole::where('gerencia_id', $id)->count();
        if ($usuarios > 0) {
            $msgs[] = "Existen {$usuarios} usuario(s) asignados a esta gerencia.";
        }

        return $msgs;
    }
}
