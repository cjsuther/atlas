<?php

namespace App\Observers;

use App\Models\HistorialCambio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Observer común para contratos_principal y contratos_ejecucion.
 *
 * Persiste cada creación, modificación campo a campo y baja lógica en
 * la tabla `historial_cambios`. La columna `tabla` se obtiene del nombre
 * real de la tabla del modelo, así sirve para ambos.
 */
class ContratoHistorialObserver
{
    public function created(Model $model): void
    {
        HistorialCambio::create([
            'tabla'            => $model->getTable(),
            'registro_id'      => (int) $model->getKey(),
            'tipo_cambio'      => 'creacion',
            'campo_modificado' => null,
            'valor_anterior'   => null,
            'valor_nuevo'      => null,
            'usuario'          => $this->currentUser(),
        ]);
    }

    public function updated(Model $model): void
    {
        // Cambio en deleted_at se maneja como baja en `deleted()`.
        $changes = $model->getChanges();
        unset($changes['updated_at'], $changes['deleted_at']);

        if (empty($changes)) return;

        $usuario = $this->currentUser();
        $tabla   = $model->getTable();
        $regId   = (int) $model->getKey();

        foreach ($changes as $campo => $nuevo) {
            $anterior = $model->getOriginal($campo);
            HistorialCambio::create([
                'tabla'            => $tabla,
                'registro_id'      => $regId,
                'tipo_cambio'      => 'modificacion',
                'campo_modificado' => $campo,
                'valor_anterior'   => $this->stringify($anterior),
                'valor_nuevo'      => $this->stringify($nuevo),
                'usuario'          => $usuario,
            ]);
        }
    }

    /** Baja lógica vía SoftDeletes. */
    public function deleted(Model $model): void
    {
        HistorialCambio::create([
            'tabla'            => $model->getTable(),
            'registro_id'      => (int) $model->getKey(),
            'tipo_cambio'      => 'baja',
            'campo_modificado' => null,
            'valor_anterior'   => null,
            'valor_nuevo'      => null,
            'usuario'          => $this->currentUser(),
        ]);
    }

    /** Restauración de baja lógica → se registra como modificación. */
    public function restored(Model $model): void
    {
        HistorialCambio::create([
            'tabla'            => $model->getTable(),
            'registro_id'      => (int) $model->getKey(),
            'tipo_cambio'      => 'modificacion',
            'campo_modificado' => 'deleted_at',
            'valor_anterior'   => 'baja',
            'valor_nuevo'      => 'restaurado',
            'usuario'          => $this->currentUser(),
        ]);
    }

    private function currentUser(): string
    {
        $user = Auth::user();
        return $user?->username ?? 'system';
    }

    private function stringify(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_scalar($v)) return (string) $v;
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
