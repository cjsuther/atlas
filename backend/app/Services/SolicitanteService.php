<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\Solicitante;

class SolicitanteService extends BaseCrudService
{
    protected string $modelClass = Solicitante::class;
    protected array $searchableFields = [
        'razon_social', 'cuil_cuit', 'rubro', 'localizacion', 'nombre_contacto',
    ];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $count = Contrato::where('solicitante_id', $id)->count();
        if ($count > 0) {
            $msgs[] = "Existen {$count} contrato(s) con este solicitante.";
        }
        return $msgs;
    }
}
