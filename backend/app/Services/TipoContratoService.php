<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\TipoContrato;

class TipoContratoService extends BaseCrudService
{
    protected string $modelClass = TipoContrato::class;
    protected array $searchableFields = ['tipo', 'nombre'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $count = Contrato::where('tipo_de_contrato_id', $id)->count();
        if ($count > 0) {
            $msgs[] = "Existen {$count} contrato(s) de este tipo.";
        }
        return $msgs;
    }
}
