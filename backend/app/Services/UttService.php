<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
use App\Models\Utt;

class UttService extends BaseCrudService
{
    protected string $modelClass = Utt::class;
    protected array $searchableFields = ['denominacion', 'nombre', 'regimen'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $cP = ContratoPrincipal::where('utt_id', $id)->count();
        if ($cP > 0) {
            $msgs[] = "Existen {$cP} contrato(s) principal(es) asociado(s) a esta UTT.";
        }
        $cE = ContratoEjecucion::where('utt_id', $id)->count();
        if ($cE > 0) {
            $msgs[] = "Existen {$cE} contrato(s) de ejecución asociado(s) a esta UTT.";
        }
        return $msgs;
    }
}
