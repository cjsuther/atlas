<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\ContratoPrincipal;
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
        $cP = ContratoPrincipal::where('solicitante_id', $id)->count();
        if ($cP > 0) {
            $msgs[] = "Existen {$cP} contrato(s) principal(es) con este solicitante.";
        }
        $cC = Contrato::where('solicitante_id', $id)->count();
        if ($cC > 0) {
            $msgs[] = "Existen {$cC} contrato(s) con este solicitante.";
        }
        return $msgs;
    }
}
