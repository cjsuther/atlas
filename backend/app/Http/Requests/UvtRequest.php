<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UvtRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('id');
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
