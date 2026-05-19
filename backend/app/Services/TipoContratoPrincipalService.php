<?php

namespace App\Services;

use App\Models\ContratoPrincipal;
use App\Models\TipoContratoPrincipal;

class TipoContratoPrincipalService extends BaseCrudService
{
    protected string $modelClass = TipoContratoPrincipal::class;
    protected array $searchableFields = ['sigla', 'nombre'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $usados = ContratoPrincipal::where('tipo_contrato_id', (int) $id)->count();
        if ($usados > 0) {
            $msgs[] = "Hay {$usados} contrato(s) principal(es) usando este tipo.";
        }
        return $msgs;
    }
}
