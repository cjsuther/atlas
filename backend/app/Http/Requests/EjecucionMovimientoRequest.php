<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EjecucionMovimientoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isStore = $this->isMethod('post');

        return [
            'tipo'           => ['required', 'in:ingreso,gasto'],
            'nro_expediente' => ['required', 'string', 'max:100',
                                 'regex:/^EX-\d{4}-\d+--APN-[A-Za-z0-9#]+$/'],

            // proveedor solo aplica a gastos; cliente solo a ingresos.
            'proveedor'      => ['nullable', 'string', 'max:300', 'required_if:tipo,gasto'],
            'cliente'        => ['nullable', 'string', 'max:300', 'required_if:tipo,ingreso'],

            'moneda'         => ['required', 'in:Peso,Dólar'],
            // En Peso: monto obligatorio. En Dólar: monto_dolares + cotizacion obligatorios
            // y el backend calcula `monto` (en pesos) = monto_dolares * cotizacion.
            'monto'          => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Peso'],
            'monto_dolares'  => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Dólar'],
            'cotizacion'     => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Dólar'],

            'objeto'         => ['required', 'string'],

            // factura: opcional, solo aplica a ingresos. PDF / JPG / PNG, hasta 10 MB.
            'factura'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // bandera del frontend para eliminar la factura existente al editar.
            'eliminar_factura' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required'              => 'Debe indicar si el movimiento es ingreso o gasto.',
            'nro_expediente.regex'       => 'El expediente debe tener el formato EX-AAAA-NNNN--APN-REPARTICIÓN.',
            'proveedor.required_if'      => 'El proveedor es obligatorio para gastos.',
            'cliente.required_if'        => 'El cliente es obligatorio para ingresos.',
            'monto.required_if'          => 'El monto en pesos es obligatorio.',
            'monto_dolares.required_if'  => 'El monto en dólares es obligatorio cuando la moneda es Dólar.',
            'cotizacion.required_if'     => 'La cotización es obligatoria cuando la moneda es Dólar.',
            'factura.mimes'              => 'La factura debe ser PDF, JPG o PNG.',
            'factura.max'                => 'La factura no puede superar los 10 MB.',
        ];
    }
}
