<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('id'); // legajo en update; null en create
        $rules = [
            'apellido'         => ['required', 'string', 'max:100'],
            'nombre'           => ['required', 'string', 'max:100'],
            'interno'          => ['nullable', 'string', 'max:20'],
            'mail'             => ['nullable', 'email', 'max:200'],
            'lugar_trabajo_id' => ['nullable', 'integer', 'exists:sector,sector_id'],
        ];

        if (!$id) {
            $rules['legajo'] = [
                'required', 'integer', 'min:1',
                Rule::unique('personal', 'legajo'),
            ];
        }

        return $rules;
    }
}
