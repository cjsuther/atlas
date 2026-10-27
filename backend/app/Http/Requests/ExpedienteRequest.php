<?php

namespace App\Http\Requests;

use App\Services\AccessScopeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de un expediente: su número y la cuenta a la que va.
 */
class ExpedienteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Un expediente por número en todo el sistema: los movimientos de
            // cualquier cuenta usan el mismo. Los dados de baja no cuentan.
            'nro_expediente'      => ['required', 'string', 'max:100',
                                      'regex:/^EX-\d{4}-\d+--APN-[A-Za-z0-9#]+$/',
                                      Rule::unique('expedientes', 'nro_expediente')
                                          ->whereNull('deleted_at')
                                          ->ignore($this->route('id'))],
            // La rama del expediente se deduce del nodo del que cuelga la cuenta.
            'cuenta_operativa_id' => ['required', 'integer', 'exists:cuentas_operativas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nro_expediente.required'      => 'El número de expediente es obligatorio.',
            'nro_expediente.regex'         => 'El expediente debe tener el formato EX-AAAA-NNNN--APN-REPARTICIÓN (ej. EX-2026-1234--APN-GAAYG#CNEA).',
            'nro_expediente.unique'        => 'Ese expediente ya está cargado: elíjalo de la lista en lugar de crearlo de nuevo.',
            'cuenta_operativa_id.required' => 'Debe indicar la cuenta a la que va el expediente.',
            'cuenta_operativa_id.exists'   => 'La cuenta indicada no existe.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Un usuario acotado sólo imputa expedientes a cuentas de su rama.
            $cuentaId = $this->input('cuenta_operativa_id');
            if ($cuentaId && !app(AccessScopeService::class)->puedeUsarCuenta((int) $cuentaId)) {
                $v->errors()->add('cuenta_operativa_id',
                    'No tiene permisos sobre esa cuenta.');
            }
        });
    }
}
