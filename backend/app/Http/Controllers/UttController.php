<?php

namespace App\Http\Controllers;

use App\Services\UttService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UttController extends CrudController
{
    public function __construct(UttService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'denominacion' => ['required', 'string', 'max:50', Rule::unique('utt', 'denominacion')],
            'nombre'       => ['required', 'string', 'max:300'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'denominacion' => [
                'required', 'string', 'max:50',
                Rule::unique('utt', 'denominacion')->ignore($id, 'utt_id'),
            ],
            'nombre' => ['required', 'string', 'max:300'],
        ];
    }
}
