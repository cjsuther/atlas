<?php

namespace App\Http\Controllers;

use App\Services\SolicitanteService;
use Illuminate\Http\Request;

class SolicitanteController extends CrudController
{
    public function __construct(SolicitanteService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'cuil_cuit'       => ['nullable', 'string', 'max:20'],
            'razon_social'    => ['required', 'string', 'max:300'],
            'rubro'           => ['nullable', 'string', 'max:200'],
            'localizacion'    => ['nullable', 'string', 'max:300'],
            'telefono'        => ['nullable', 'string', 'max:100'],
            'nombre_contacto' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return $this->rulesForStore($request);
    }
}
