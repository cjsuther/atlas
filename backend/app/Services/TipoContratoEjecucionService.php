<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\TipoContratoEjecucion;

class TipoContratoEjecucionService extends BaseCrudService
{
    protected string $modelClass = TipoContratoEjecucion::class;
    protected array $searchableFields = ['sigla', 'nombre'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $usados = Contrato::where('tipo_contrato_id', (int) $id)->count();
        if ($usados > 0) {
            $msgs[] = "Hay {$usados} contrato(s) usando este tipo.";
        }
        return $msgs;
    }
}
