<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolicitanteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
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
}
