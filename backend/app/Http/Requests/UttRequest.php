<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UttRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'denominacion' => [
                'required', 'string', 'max:50',
                Rule::unique('utt', 'denominacion')->ignore($id, 'utt_id'),
            ],
            'nombre'       => ['required', 'string', 'max:300'],
        ];
    }
}
