<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\EstadoEjecucion;

class EstadoEjecucionService extends BaseCrudService
{
    protected string $modelClass = EstadoEjecucion::class;
    protected array $searchableFields = ['nombre', 'descripcion'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $usados = ContratoEjecucion::where('estado_id', (int) $id)->count();
        if ($usados > 0) {
            $msgs[] = "Hay {$usados} contrato(s) de ejecución en este estado.";
        }
        return $msgs;
    }
}
