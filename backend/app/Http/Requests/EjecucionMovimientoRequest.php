<?php

namespace App\Http\Requests;

use App\Models\Expediente;
use App\Models\EjecucionMovimiento;
use App\Services\AccessScopeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de un movimiento de ejecución.
 *
 * El movimiento se registra en una cuenta. El contrato es opcional: dice con
 * qué convenio se relaciona.
 *
 * La contraparte depende de la acción:
 *   factura       -> cliente (ingreso) o proveedor (gasto)
 *   transferencia -> otra cuenta operativa
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
            // El expediente se elige de los cargados en el sistema, no se escribe.
            'expediente_id'  => ['required', 'integer',
                                 Rule::exists('expedientes', 'id')->whereNull('deleted_at')],

            // Contraparte: cada campo aplica sólo a ciertas acciones (ver withValidator).
            'proveedor'               => ['nullable', 'string', 'max:300'],
            'cliente'                 => ['nullable', 'string', 'max:300'],
            'cuenta_contraparte_id'   => ['nullable', 'integer', 'exists:cuentas_operativas,id'],
            'rubro'                   => ['nullable', 'string', 'max:200'],

            'moneda'         => ['required', 'in:Peso,Dólar'],
            // En Peso: monto obligatorio. En Dólar: monto_dolares + cotizacion obligatorios
            // y el backend calcula `monto` (en pesos) = monto_dolares * cotizacion.
            'monto'          => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Peso'],
            'monto_dolares'  => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Dólar'],
            'cotizacion'     => ['nullable', 'numeric', 'min:0', 'required_if:moneda,Dólar'],

            'objeto'         => ['required', 'string'],

            // factura: opcional, sólo aplica a gastos por factura. PDF / JPG / PNG, hasta 10 MB.
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
            'expediente_id.required'     => 'Debe elegir el expediente del movimiento.',
            'expediente_id.exists'       => 'El expediente indicado no existe.',
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
                    if (!$this->filled('cuenta_contraparte_id')) {
                        $v->errors()->add('cuenta_contraparte_id',
                            'Debe indicar la cuenta con la que se hace la transferencia.');
                    } elseif ($this->cuentaActualId() === (int) $this->input('cuenta_contraparte_id')) {
                        $v->errors()->add('cuenta_contraparte_id',
                            'No se puede transferir una cuenta a sí misma.');
                    } elseif (!app(AccessScopeService::class)->puedeUsarCuenta((int) $this->input('cuenta_contraparte_id'))) {
                        // La transferencia escribe la contrapartida en la otra
                        // cuenta, así que hace falta escritura sobre ella.
                        $v->errors()->add('cuenta_contraparte_id',
                            'No tiene permisos sobre la cuenta de la contraparte.');
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

            // El expediente tiene que estar dentro del alcance del usuario.
            if ($this->filled('expediente_id')) {
                $contrato = Expediente::find((int) $this->input('expediente_id'));
                if ($contrato && !app(AccessScopeService::class)->puedeVerContrato($contrato)) {
                    $v->errors()->add('expediente_id', 'El expediente no está a su alcance.');
                }
            }

            if ($this->hasFile('factura')
                && ($accion !== EjecucionMovimiento::ACCION_FACTURA || $tipo !== 'gasto')) {
                $v->errors()->add('factura', 'Sólo se adjunta factura en los gastos por factura.');
            }
        });
    }

    /** Cuenta en la que se registra el movimiento (alta por ruta, edición por el registro). */
    private function cuentaActualId(): ?int
    {
        $desdeRuta = $this->route('id');

        if ($this->isMethod('post') && !$this->input('_method')) {
            return $desdeRuta !== null ? (int) $desdeRuta : null;
        }

        $movimiento = $desdeRuta !== null
            ? EjecucionMovimiento::withTrashed()->find((int) $desdeRuta)
            : null;

        return $movimiento ? (int) $movimiento->cuenta_operativa_id : null;
    }
}
