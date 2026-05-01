<?php

namespace App\Http\Controllers;

use App\Services\TipoContratoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TipoContratoController extends CrudController
{
    public function __construct(TipoContratoService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'tipo'   => [
                'required', 'string', 'max:20',
                Rule::unique('tipo_de_contrato', 'tipo'),
            ],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'tipo'   => [
                'required', 'string', 'max:20',
                Rule::unique('tipo_de_contrato', 'tipo')->ignore($id, 'id_tipo'),
            ],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }
}
