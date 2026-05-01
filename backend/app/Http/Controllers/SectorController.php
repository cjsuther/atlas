<?php

namespace App\Http\Controllers;

use App\Services\SectorService;
use Illuminate\Http\Request;

class SectorController extends CrudController
{
    public function __construct(SectorService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'nombre'         => ['required', 'string', 'max:200'],
            'dependencia_id' => ['nullable', 'integer', 'exists:sector,sector_id'],
            'responsable'    => ['nullable', 'string', 'max:200'],
            'web'            => ['nullable', 'string', 'max:300'],
            'ubicacion'      => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        $rules = $this->rulesForStore($request);
        $rules['dependencia_id'][] = function ($attribute, $value, $fail) use ($id) {
            if ($value !== null && (int) $value === (int) $id) {
                $fail('Un sector no puede depender de sí mismo.');
            }
        };
        return $rules;
    }
}
