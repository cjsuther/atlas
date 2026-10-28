<?php

namespace App\Support;

use App\Models\EjecucionMovimiento;
use App\Models\LogActividad;
use Illuminate\Support\Facades\Auth;

/** Anota en el log de actividades lo que se hace con un movimiento. */
class RegistroActividad
{
    public static function movimiento(
        string $accion,
        EjecucionMovimiento $m,
        ?string $descripcion = null,
        ?string $observacion = null,
    ): void {
        LogActividad::create([
            'fecha'               => now(),
            'usuario'             => Auth::user()?->username ?? 'system',
            'accion'              => $accion,
            'tabla'               => 'ejecucion_movimientos',
            'registro_id'         => $m->id,
            'cuenta_operativa_id' => $m->cuenta_operativa_id,
            'descripcion'         => mb_substr($descripcion ?? self::describir($m), 0, 1000),
            'observacion'         => $observacion !== null && trim($observacion) !== '' ? trim($observacion) : null,
        ]);
    }

    /** «Movimiento #12 · Ingreso por factura · $ 1.000,00 · Expte. X». */
    public static function describir(EjecucionMovimiento $m): string
    {
        $accion = [
            EjecucionMovimiento::ACCION_FACTURA       => 'por factura',
            EjecucionMovimiento::ACCION_TRANSFERENCIA => 'por transferencia',
            EjecucionMovimiento::ACCION_INCENTIVO     => 'por incentivo',
            EjecucionMovimiento::ACCION_MCH           => 'por MCH',
        ][$m->accion] ?? '';

        $partes = ['Movimiento #' . $m->id, trim(ucfirst((string) $m->tipo) . ' ' . $accion)];
        if ($m->monto !== null) {
            $partes[] = '$ ' . number_format((float) $m->monto, 2, ',', '.');
        }
        if ($m->nro_expediente) {
            $partes[] = 'Expte. ' . $m->nro_expediente;
        }
        return implode(' · ', $partes);
    }
}
