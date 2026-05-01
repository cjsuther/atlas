<?php

namespace App\Http\Controllers;

use App\Services\PersonalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonalController extends CrudController
{
    public function __construct(PersonalService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'legajo'           => ['required', 'integer', 'min:1', Rule::unique('personal', 'legajo')],
            'apellido'         => ['required', 'string', 'max:100'],
            'nombre'           => ['required', 'string', 'max:100'],
            'interno'          => ['nullable', 'string', 'max:20'],
            'mail'             => ['nullable', 'email', 'max:200'],
            'lugar_trabajo_id' => ['nullable', 'integer', 'exists:sector,sector_id'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'apellido'         => ['required', 'string', 'max:100'],
            'nombre'           => ['required', 'string', 'max:100'],
            'interno'          => ['nullable', 'string', 'max:20'],
            'mail'             => ['nullable', 'email', 'max:200'],
            'lugar_trabajo_id' => ['nullable', 'integer', 'exists:sector,sector_id'],
        ];
    }
}
