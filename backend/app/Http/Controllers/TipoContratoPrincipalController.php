<?php

namespace App\Http\Controllers;

use App\Services\TipoContratoPrincipalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TipoContratoPrincipalController extends CrudController
{
    public function __construct(TipoContratoPrincipalService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'sigla'  => ['required', 'string', 'max:20', Rule::unique('tipo_contrato_principal', 'sigla')],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'sigla'  => ['required', 'string', 'max:20',
                         Rule::unique('tipo_contrato_principal', 'sigla')->ignore($id, 'id')],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }
}
