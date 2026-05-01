<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SectorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombre'         => ['required', 'string', 'max:200'],
            'dependencia_id' => ['nullable', 'integer', 'exists:sector,sector_id'],
            'responsable'    => ['nullable', 'string', 'max:200'],
            'web'            => ['nullable', 'string', 'max:300'],
            'ubicacion'      => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $id = $this->route('id');
            if ($id && $this->input('dependencia_id') && (int)$this->input('dependencia_id') === (int)$id) {
                $v->errors()->add('dependencia_id', 'Un sector no puede depender de sí mismo.');
            }
        });
    }
}
