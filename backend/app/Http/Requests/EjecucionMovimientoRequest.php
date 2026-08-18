<?php

namespace App\Http\Requests;

use App\Models\EjecucionMovimiento;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de un movimiento de ejecución.
 *
 * La contraparte depende de la acción:
 *   factura       -> cliente (ingreso) o proveedor (gasto)
 *   transferencia -> otro contrato de ejecución
 *   incentivo/mch -> rubro (son siempre gastos)
 */
class EjecucionMovimientoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tipo'           => ['required', 'in:ingreso,gasto'],
            'accion'         => ['required', 'in:' . implode(',', EjecucionMovimiento::ACCIONES)],
            'nro_expediente' => ['required', 'string', 'max:100',
                                 'regex:/^EX-\d{4}-\d+--APN-[A-Za-z0-9#]+$/'],

            // Contraparte: cada campo aplica sólo a ciertas acciones (ver withValidator).
            'proveedor'               => ['nullable', 'string', 'max:300'],
            'cliente'                 => ['nullable', 'string', 'max:300'],
            'contrato_contraparte_id' => ['nullable', 'integer', 'exists:contratos_ejecucion,id'],
            'rubro'                   => ['nullable', 'string', 'max:200'],

            'moneda'         => ['required', 'in:Peso,Dólar'],
            // En Peso: monto obligatorio. En Dólar: monto_dolares + cotizacion obligatorios
            // y el backend calcula `monto` (en pesos) = monto_dolares * cotizacion.
            'monto'          => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Peso'],
            'monto_dolares'  => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Dólar'],
            'cotizacion'     => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Dólar'],

            'objeto'         => ['required', 'string'],

            // factura: opcional, sólo aplica a ingresos por factura. PDF / JPG / PNG, hasta 10 MB.
            'factura'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // bandera del frontend para eliminar la factura existente al editar.
            'eliminar_factura' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required'              => 'Debe indicar si el movimiento es ingreso o gasto.',
            'accion.required'            => 'Debe indicar la acción (factura, transferencia, incentivo o MCH).',
            'nro_expediente.regex'       => 'El expediente debe tener el formato EX-AAAA-NNNN--APN-REPARTICIÓN.',
            'monto.required_if'          => 'El monto en pesos es obligatorio.',
            'monto_dolares.required_if'  => 'El monto en dólares es obligatorio cuando la moneda es Dólar.',
            'cotizacion.required_if'     => 'La cotización es obligatoria cuando la moneda es Dólar.',
            'factura.mimes'              => 'La factura debe ser PDF, JPG o PNG.',
            'factura.max'                => 'La factura no puede superar los 10 MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $accion = $this->input('accion');
            $tipo   = $this->input('tipo');

            switch ($accion) {
                case EjecucionMovimiento::ACCION_FACTURA:
                    if ($tipo === 'gasto' && !$this->filled('proveedor')) {
                        $v->errors()->add('proveedor', 'El proveedor es obligatorio en un gasto por factura.');
                    }
                    if ($tipo === 'ingreso' && !$this->filled('cliente')) {
                        $v->errors()->add('cliente', 'El cliente es obligatorio en un ingreso por factura.');
                    }
                    break;

                case EjecucionMovimiento::ACCION_TRANSFERENCIA:
                    if (!$this->filled('contrato_contraparte_id')) {
                        $v->errors()->add('contrato_contraparte_id',
                            'Debe indicar el contrato con el que se hace la transferencia.');
                    } elseif ($this->contratoActualId() === (int) $this->input('contrato_contraparte_id')) {
                        $v->errors()->add('contrato_contraparte_id',
                            'No se puede transferir un contrato a sí mismo.');
                    }
                    break;

                case EjecucionMovimiento::ACCION_INCENTIVO:
                case EjecucionMovimiento::ACCION_MCH:
                    if ($tipo !== 'gasto') {
                        $v->errors()->add('tipo', 'Los incentivos y la MCH se registran siempre como gasto.');
                    }
                    if (!$this->filled('rubro')) {
                        $v->errors()->add('rubro', 'Debe indicar el rubro del pago.');
                    }
                    break;
            }

            if ($this->hasFile('factura')
                && ($accion !== EjecucionMovimiento::ACCION_FACTURA || $tipo !== 'ingreso')) {
                $v->errors()->add('factura', 'Sólo se adjunta factura en los ingresos por factura.');
            }
        });
    }

    /** Contrato al que pertenece el movimiento (alta por ruta, edición por el registro). */
    private function contratoActualId(): ?int
    {
        $desdeRuta = $this->route('id');

        if ($this->isMethod('post') && !$this->input('_method')) {
            return $desdeRuta !== null ? (int) $desdeRuta : null;
        }

        $movimiento = $desdeRuta !== null
            ? EjecucionMovimiento::withTrashed()->find((int) $desdeRuta)
            : null;

        return $movimiento ? (int) $movimiento->contrato_ejecucion_id : null;
    }
}
