<?php

namespace App\Http\Requests;

use App\Models\EstadoEjecucion;
use App\Models\TipoContratoEjecucion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ContratoEjecucionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Reglas 1, 2, 3
            'nro_expediente'             => ['required', 'string', 'max:100',
                                              'regex:/^EX-\d{4}-\d+--APN-[A-Za-z0-9#]+$/'],
            'tipo_contrato_id'           => ['required', 'integer', 'exists:tipo_contrato_ejecucion,id'],
            'estado_id'                  => ['required', 'integer', 'exists:estado_ejecucion,id'],

            'fecha_apertura_expediente'  => ['nullable', 'date'],
            'nombre_proyecto'            => ['required', 'string', 'max:500'],
            'descripcion_objeto'         => ['nullable', 'string'],

            // Regla 4: contrato_principal_id obligatorio salvo AP — validado en withValidator
            'contrato_principal_id'      => ['nullable', 'integer', 'exists:contratos_principal,id'],

            'gerencia_area'              => ['nullable', 'string', 'max:200'],
            'gerencia'                   => ['nullable', 'string', 'max:200'],
            'solicitante_id'             => ['nullable', 'integer', 'exists:solicitantes,solicitante_id'],
            'resp1_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'resp2_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'utt_id'                     => ['nullable', 'integer', 'exists:utt,utt_id'],
            'observaciones'              => ['nullable', 'string'],
            'uvt_id'                     => ['nullable', 'integer', 'exists:uvt,uvt_id'],
            'cliente'                    => ['nullable', 'string', 'max:300'],

            'fecha_inicio'               => ['nullable', 'date', 'after_or_equal:fecha_apertura_expediente'],
            'fecha_vencimiento'          => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_finalizacion'         => ['nullable', 'date', 'after_or_equal:fecha_inicio'],

            'acta_finalizacion'          => ['nullable', 'string', 'max:500'],
            'prorroga'                   => ['nullable', 'boolean'],
            'renovacion_automatica'      => ['nullable', 'boolean'],
            'caja_bas'                   => ['nullable', 'string', 'max:200'],
            'moneda'                     => ['required', 'in:Peso,Dólar,Euro,Otro'],
            'cotizacion'                 => ['nullable', 'numeric', 'min:0', 'required_unless:moneda,Peso'],
            'monto_presupuestado_ingresos' => ['nullable', 'numeric', 'min:0'],
            'monto_presupuestado_gastos'   => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'nro_expediente.required'           => 'El número de expediente es obligatorio.',
            'nro_expediente.regex'              => 'El expediente debe tener el formato EX-AAAA-NNNN--APN-REPARTICIÓN (ej. EX-2026-1234--APN-GVTYEA#CNEA).',
            'tipo_contrato_id.required'         => 'Debe seleccionar el tipo de contrato.',
            'estado_id.required'                => 'Debe indicar el estado.',
            'fecha_inicio.after_or_equal'       => 'La fecha de inicio debe ser igual o posterior a la fecha de apertura del expediente.',
            'fecha_vencimiento.after_or_equal'  => 'La fecha de vencimiento debe ser igual o posterior a la fecha de inicio.',
            'fecha_finalizacion.after_or_equal' => 'La fecha de finalización no puede ser anterior a la fecha de inicio.',
            'cotizacion.required_unless'        => 'La cotización es obligatoria cuando la moneda no es Peso.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Regla 4: contrato_principal_id obligatorio salvo que el tipo sea AP.
            // En contratos_ejecucion el catálogo no incluye AP (AP es de principal),
            // por lo que en la práctica SIEMPRE debe haber contrato_principal_id.
            // Excepción: si en el futuro se crea un tipo "AP" en ejecución, se respeta.
            $tipoId = $this->input('tipo_contrato_id');
            $tipo   = $tipoId ? TipoContratoEjecucion::find($tipoId) : null;
            $esAP   = $tipo && strtoupper($tipo->sigla) === 'AP';

            if (!$esAP && !$this->filled('contrato_principal_id')) {
                $v->errors()->add('contrato_principal_id',
                    'Debe vincularse a un contrato principal (excepto si el tipo es AP).');
            }

            $estadoId = $this->input('estado_id');
            $estado   = $estadoId ? EstadoEjecucion::find($estadoId) : null;

            // Regla 7
            if ($estado && $estado->nombre === 'Finalizado' && !$this->filled('fecha_finalizacion')) {
                $v->errors()->add('fecha_finalizacion',
                    'Si el estado es "Finalizado", la fecha de finalización es obligatoria.');
            }

            // Regla 8
            if ($this->boolean('prorroga')
                && !$this->filled('fecha_vencimiento')
                && !$this->filled('observaciones')) {
                $v->errors()->add('prorroga',
                    'Si hay prórroga, debe indicar nueva fecha de vencimiento o detalle en observaciones.');
            }
        });
    }
}
