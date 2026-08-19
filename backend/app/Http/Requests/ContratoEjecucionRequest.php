<?php

namespace App\Http\Requests;

use App\Models\EstadoEjecucion;
use App\Services\AccessScopeService;
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

            // Todo contrato pertenece a una gerencia, y ésta a una Gerencia de Área.
            'gerencia_id'                => ['required', 'integer', 'exists:gerencias,id'],
            // Departamento o laboratorio dentro de la gerencia.
            'sector_detalle'             => ['nullable', 'string', 'max:200'],
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
            'gerencia_id.required'              => 'Debe indicar la gerencia a la que pertenece el contrato.',
            'gerencia_id.exists'                => 'La gerencia indicada no existe.',
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
            // Un usuario acotado sólo puede imputar contratos a su propia gerencia.
            $gerenciaId = $this->input('gerencia_id');
            if ($gerenciaId && !app(AccessScopeService::class)->puedeUsarGerencia((int) $gerenciaId)) {
                $v->errors()->add('gerencia_id',
                    'No tiene permisos para cargar contratos en esa gerencia.');
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
