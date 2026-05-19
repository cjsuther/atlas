<?php

namespace App\Http\Controllers;

use App\Services\EstadoPrincipalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstadoPrincipalController extends CrudController
{
    public function __construct(EstadoPrincipalService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('estado_principal', 'nombre')],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100',
                         Rule::unique('estado_principal', 'nombre')->ignore($id, 'id')],
        ];
    }
}
