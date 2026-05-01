<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContratoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombre_proy'                => ['required', 'string', 'max:500'],
            'dependencia_contractual_id' => ['nullable', 'integer', 'exists:contratos,id_cto'],
            'operatoria_id'              => ['nullable', 'integer'],
            'fecha_expediente'           => ['nullable', 'date'],
            'estado_id'                  => ['nullable', 'integer', 'exists:estado,estado_id'],
            'expediente'                 => ['nullable', 'string', 'max:200'],
            'solicitud_sector_gde'       => ['nullable', 'string', 'max:300'],
            'descripcion_objeto'         => ['nullable', 'string'],
            'tipo_de_contrato_id'        => ['nullable', 'integer', 'exists:tipo_de_contrato,id_tipo'],
            'observaciones'              => ['nullable', 'string'],
            'solicitante_id'             => ['nullable', 'integer', 'exists:solicitantes,solicitante_id'],
            'uvt_id'                     => ['nullable', 'integer', 'exists:uvt,uvt_id'],
            'sector_id'                  => ['nullable', 'integer', 'exists:sector,sector_id'],
            'gerencia'                   => ['nullable', 'string', 'max:200'],
            'gerencia_area'              => ['nullable', 'string', 'max:200'],
            'fecha_firma'                => ['nullable', 'date'],
            'fecha_inicio'               => ['nullable', 'date'],
            'fecha_vencimiento'          => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_finalizado'           => ['nullable', 'date'],
            'duracion_meses'             => ['nullable', 'integer', 'min:0'],
            'atraso_meses'               => ['nullable', 'integer', 'min:0'],
            'prorroga'                   => ['nullable', 'boolean'],
            'renovacion_automatica'      => ['nullable', 'boolean'],
            'acta_finalizacion'          => ['nullable', 'string', 'max:500'],
            'resp1_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'resp2_id'                   => ['nullable', 'integer', 'exists:personal,legajo'],
            'caja_bas'                   => ['nullable', 'string', 'max:200'],
            'resp_caja'                  => ['nullable', 'string', 'max:200'],
            'monto_pesos'                => ['nullable', 'numeric', 'min:0'],
            'monto_usd'                  => ['nullable', 'numeric', 'min:0'],
            'monto_euros'                => ['nullable', 'numeric', 'min:0'],
            'monto_otro'                 => ['nullable', 'numeric', 'min:0'],
            'moneda_otro'                => ['nullable', 'string', 'max:50'],
            'automatico_ejecucion'       => ['nullable', 'boolean'],
            'automatico_finalizado'      => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_vencimiento.after_or_equal' => 'La fecha de vencimiento debe ser igual o posterior a la fecha de inicio.',
        ];
    }
}
