<?php

namespace App\Http\Controllers;

use App\Services\UvtService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UvtController extends CrudController
{
    public function __construct(UvtService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'siglas'      => ['required', 'string', 'max:50', Rule::unique('uvt', 'siglas')],
            'nombre'      => ['required', 'string', 'max:300'],
            'responsable' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'siglas'      => [
                'required', 'string', 'max:50',
                Rule::unique('uvt', 'siglas')->ignore($id, 'uvt_id'),
            ],
            'nombre'      => ['required', 'string', 'max:300'],
            'responsable' => ['nullable', 'string', 'max:200'],
        ];
    }
}
