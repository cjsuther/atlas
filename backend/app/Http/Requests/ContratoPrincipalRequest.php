<?php

namespace App\Http\Requests;

use App\Models\EstadoPrincipal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ContratoPrincipalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Reglas 1, 2, 3 — campos obligatorios
            'nro_expediente'             => ['required', 'string', 'max:100',
                                              'regex:/^EX-\d{4}-\d+--APN-[A-Za-z0-9#]+$/'],
            'tipo_contrato_id'           => ['required', 'integer', 'exists:tipo_contrato_principal,id'],
            'estado_id'                  => ['required', 'integer', 'exists:estado_principal,id'],

            'fecha_apertura_expediente'  => ['nullable', 'date'],
            'regimen'                    => ['required', 'in:160,317'],
            'nombre_proyecto'            => ['required', 'string', 'max:500'],
            'descripcion_objeto'         => ['nullable', 'string'],
            'gerencia_area'              => ['nullable', 'string', 'max:200'],
            'gerencia'                   => ['nullable', 'string', 'max:200'],
            'solicitante_id'             => ['nullable', 'integer', 'exists:solicitantes,solicitante_id'],
            'resp1_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'resp2_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'utt_id'                     => ['nullable', 'integer', 'exists:utt,utt_id'],
            'observaciones'              => ['nullable', 'string'],
            'uvt_id'                     => ['nullable', 'integer', 'exists:uvt,uvt_id'],
            'cliente'                    => ['nullable', 'string', 'max:300'],

            // Regla 5: fecha_apertura ≤ fecha_inicio ≤ fecha_vencimiento
            'fecha_inicio'               => ['nullable', 'date', 'after_or_equal:fecha_apertura_expediente'],
            'fecha_vencimiento'          => ['nullable', 'date', 'after_or_equal:fecha_inicio'],

            // Regla 6: fecha_finalizacion no anterior a fecha_inicio
            'fecha_finalizacion'         => ['nullable', 'date', 'after_or_equal:fecha_inicio'],

            'acta_finalizacion'          => ['nullable', 'string', 'max:500'],
            'prorroga'                   => ['nullable', 'boolean'],
            'renovacion_automatica'      => ['nullable', 'boolean'],
            'caja_bas'                   => ['nullable', 'string', 'max:200'],
            'moneda'                     => ['required', 'in:Peso,Dólar,Euro,Otro'],

            // Regla 9: cotización requerida si moneda ≠ Peso
            'cotizacion'                 => ['nullable', 'numeric', 'min:0', 'required_unless:moneda,Peso'],

            'monto_presupuestado_ingresos' => ['nullable', 'numeric', 'min:0'],
            'monto_presupuestado_gastos'   => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'nro_expediente.required'         => 'El número de expediente es obligatorio.',
            'nro_expediente.regex'            => 'El expediente debe tener el formato EX-AAAA-NNNN--APN-REPARTICIÓN (ej. EX-2026-1234--APN-GVTYEA#CNEA).',
            'tipo_contrato_id.required'       => 'Debe seleccionar el tipo de contrato.',
            'estado_id.required'              => 'Debe indicar el estado.',
            'regimen.required'                => 'Debe indicar el régimen (160 ó 317).',
            'fecha_inicio.after_or_equal'     => 'La fecha de inicio debe ser igual o posterior a la fecha de apertura del expediente.',
            'fecha_vencimiento.after_or_equal'=> 'La fecha de vencimiento debe ser igual o posterior a la fecha de inicio.',
            'fecha_finalizacion.after_or_equal'=> 'La fecha de finalización no puede ser anterior a la fecha de inicio.',
            'cotizacion.required_unless'      => 'La cotización es obligatoria cuando la moneda no es Peso.',
        ];
    }

    /** Reglas 7 y 8: validaciones condicionales por estado/prórroga. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $estadoId = $this->input('estado_id');
            $estado   = $estadoId ? EstadoPrincipal::find($estadoId) : null;

            // Regla 7: si estado = "Finalizado" → fecha_finalizacion obligatoria
            if ($estado && $estado->nombre === 'Finalizado' && !$this->filled('fecha_finalizacion')) {
                $v->errors()->add('fecha_finalizacion',
                    'Si el estado es "Finalizado", la fecha de finalización es obligatoria.');
            }

            // Regla 8: si prorroga = true → debe haber nueva fecha_vencimiento o texto en observaciones
            if ($this->boolean('prorroga')
                && !$this->filled('fecha_vencimiento')
                && !$this->filled('observaciones')) {
                $v->errors()->add('prorroga',
                    'Si hay prórroga, debe indicar nueva fecha de vencimiento o detalle en observaciones.');
            }
        });
    }
}
