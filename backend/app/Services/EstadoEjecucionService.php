<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\EstadoEjecucion;

class EstadoEjecucionService extends BaseCrudService
{
    protected string $modelClass = EstadoEjecucion::class;
    protected array $searchableFields = ['nombre', 'descripcion'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $usados = Contrato::where('estado_id', (int) $id)->count();
        if ($usados > 0) {
            $msgs[] = "Hay {$usados} contrato(s) en este estado.";
        }
        return $msgs;
    }
}
