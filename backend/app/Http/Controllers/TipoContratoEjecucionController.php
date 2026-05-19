<?php

namespace App\Http\Controllers;

use App\Services\TipoContratoEjecucionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TipoContratoEjecucionController extends CrudController
{
    public function __construct(TipoContratoEjecucionService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'sigla'  => ['required', 'string', 'max:20', Rule::unique('tipo_contrato_ejecucion', 'sigla')],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'sigla'  => ['required', 'string', 'max:20',
                         Rule::unique('tipo_contrato_ejecucion', 'sigla')->ignore($id, 'id')],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }
}
