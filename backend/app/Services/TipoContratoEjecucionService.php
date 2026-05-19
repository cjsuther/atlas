<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\TipoContratoEjecucion;

class TipoContratoEjecucionService extends BaseCrudService
{
    protected string $modelClass = TipoContratoEjecucion::class;
    protected array $searchableFields = ['sigla', 'nombre'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $usados = ContratoEjecucion::where('tipo_contrato_id', (int) $id)->count();
        if ($usados > 0) {
            $msgs[] = "Hay {$usados} contrato(s) de ejecución usando este tipo.";
        }
        return $msgs;
    }
}
