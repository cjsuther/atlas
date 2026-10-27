<?php

namespace App\Http\Requests;

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
            // Fecha del comprobante: la de la factura, la transferencia o el pago.
            'fecha'          => ['required', 'date'],

            // factura: opcional, sólo aplica a gastos por factura. PDF / JPG / PNG, hasta 10 MB.
            'factura'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // bandera del frontend para eliminar la factura existente al editar.
            'eliminar_factura' => ['nullable', 'boolean'],

            // Reparto de un ingreso por factura entre otras cuentas. `distribuir`
            // dice que viene el reparto completo (vacío lo quita); sin él, el
            // reparto existente se conserva y se recalcula sobre el monto.
            'distribuir'                         => ['nullable', 'boolean'],
            'distribucion'                       => ['nullable', 'array', 'max:50'],
            'distribucion.*.cuenta_operativa_id' => ['required', 'integer', 'distinct', 'exists:cuentas_operativas,id'],
            'distribucion.*.porcentaje'          => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required'              => 'Debe indicar si el movimiento es ingreso o gasto.',
            'accion.required'            => 'Debe indicar la acción (factura, transferencia, incentivo o MCH).',
            'expediente_id.required'     => 'Debe elegir el expediente del movimiento.',
            'expediente_id.exists'       => 'El expediente indicado no existe.',
            'fecha.required'             => 'Indique la fecha del comprobante.',
            'fecha.date'                 => 'La fecha del comprobante no es válida.',
            'monto.required_if'          => 'El monto en pesos es obligatorio.',
            'monto_dolares.required_if'  => 'El monto en dólares es obligatorio cuando la moneda es Dólar.',
            'cotizacion.required_if'     => 'La cotización es obligatoria cuando la moneda es Dólar.',
            'factura.mimes'              => 'La factura debe ser PDF, JPG o PNG.',
            'factura.max'                => 'La factura no puede superar los 10 MB.',
            'distribucion.*.cuenta_operativa_id.required' => 'Elija la cuenta de cada parte del reparto.',
            'distribucion.*.cuenta_operativa_id.distinct' => 'Una cuenta aparece más de una vez en el reparto.',
            'distribucion.*.cuenta_operativa_id.exists'   => 'Una de las cuentas del reparto no existe.',
            'distribucion.*.porcentaje.required'          => 'Indique el porcentaje de cada parte del reparto.',
            'distribucion.*.porcentaje.gt'                => 'Cada porcentaje del reparto tiene que ser mayor que cero.',
            'distribucion.*.porcentaje.max'               => 'Un porcentaje del reparto no puede superar el 100 %.',
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

            // El expediente puede ser de cualquier rama: es uno solo para todo
            // el sistema y una distribución de fondos lo lleva a varias cuentas.

            $this->validarDistribucion($v, $accion, $tipo);

            if ($this->hasFile('factura')
                && ($accion !== EjecucionMovimiento::ACCION_FACTURA || $tipo !== 'gasto')) {
                $v->errors()->add('factura', 'Sólo se adjunta factura en los gastos por factura.');
            }
        });
    }

    /**
     * El reparto es sólo de ingresos por factura, a otras cuentas en las que
     * el usuario puede registrar, y no puede pasar del total.
     */
    private function validarDistribucion(Validator $v, ?string $accion, ?string $tipo): void
    {
        $partes = $this->input('distribucion') ?? [];
        if (!is_array($partes) || !$partes) {
            return;
        }

        if ($accion !== EjecucionMovimiento::ACCION_FACTURA || $tipo !== 'ingreso') {
            $v->errors()->add('distribucion', 'Sólo se reparte un ingreso por factura.');
            return;
        }

        $total = 0.0;
        $scope = app(AccessScopeService::class);
        foreach ($partes as $i => $p) {
            $total += (float) ($p['porcentaje'] ?? 0);
            $cuenta = (int) ($p['cuenta_operativa_id'] ?? 0);
            if ($cuenta === $this->cuentaActualId()) {
                $v->errors()->add("distribucion.{$i}.cuenta_operativa_id",
                    'Lo que no se reparte ya queda en esta cuenta: no hace falta incluirla.');
            } elseif ($cuenta && !$scope->puedeUsarCuenta($cuenta)) {
                $v->errors()->add("distribucion.{$i}.cuenta_operativa_id",
                    'No tiene permisos para registrar en una de las cuentas del reparto.');
            }
        }

        if ($total > 100.0001) {
            $v->errors()->add('distribucion',
                'Los porcentajes del reparto suman ' . rtrim(rtrim(number_format($total, 2, ',', ''), '0'), ',')
                . ' %: no pueden pasar del 100 %.');
        }
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
