<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoContratoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'tipo'   => [
                'required', 'string', 'max:20',
                Rule::unique('tipo_de_contrato', 'tipo')->ignore($id, 'id_tipo'),
            ],
            'nombre' => ['required', 'string', 'max:200'],
        ];
    }
}
