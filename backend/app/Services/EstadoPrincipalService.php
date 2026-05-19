<?php

namespace App\Services;

use App\Models\ContratoPrincipal;
use App\Models\EstadoPrincipal;

class EstadoPrincipalService extends BaseCrudService
{
    protected string $modelClass = EstadoPrincipal::class;
    protected array $searchableFields = ['nombre'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $usados = ContratoPrincipal::where('estado_id', (int) $id)->count();
        if ($usados > 0) {
            $msgs[] = "Hay {$usados} contrato(s) principal(es) en este estado.";
        }
        return $msgs;
    }
}
