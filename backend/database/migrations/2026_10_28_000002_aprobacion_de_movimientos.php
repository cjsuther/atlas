<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se registra en una cuenta no mueve el saldo hasta que lo aprueba un
 * aprobador con alcance sobre esa cuenta.
 *
 * - `user_roles.es_aprobador`: la marca que habilita a aprobar o rechazar.
 * - `ejecucion_movimientos.estado_aprobacion`: pendiente, aprobado o
 *   rechazado. Sólo lo aprobado suma en los saldos. Lo que ya estaba cargado
 *   queda aprobado: es lo que la gerencia venía informando.
 * - `cambio_propuesto`: lo que se pidió modificar o dar de baja de un
 *   movimiento aprobado; se aplica recién cuando se aprueba.
 * - `log_actividades`: quién pidió, aprobó o rechazó, cuándo y con qué
 *   observación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_roles', 'es_aprobador')) {
            DB::statement('ALTER TABLE user_roles
                ADD COLUMN es_aprobador TINYINT(1) NOT NULL DEFAULT 0 AFTER es_admin');
        }

        if (!Schema::hasColumn('ejecucion_movimientos', 'estado_aprobacion')) {
            DB::statement("ALTER TABLE ejecucion_movimientos
                ADD COLUMN estado_aprobacion VARCHAR(20) NOT NULL DEFAULT 'aprobado' AFTER distribucion_porcentaje,
                ADD COLUMN cargado_por VARCHAR(200) NULL AFTER estado_aprobacion,
                ADD COLUMN aprobacion_usuario VARCHAR(200) NULL AFTER cargado_por,
                ADD COLUMN aprobacion_fecha DATETIME NULL AFTER aprobacion_usuario,
                ADD COLUMN aprobacion_observacion TEXT NULL AFTER aprobacion_fecha,
                ADD COLUMN cambio_propuesto LONGTEXT NULL AFTER aprobacion_observacion,
                ADD COLUMN cambio_propuesto_por VARCHAR(200) NULL AFTER cambio_propuesto,
                ADD COLUMN cambio_propuesto_fecha DATETIME NULL AFTER cambio_propuesto_por,
                ADD KEY idx_em_aprobacion (estado_aprobacion)");

            // Quién cargó cada movimiento ya está en el historial.
            DB::statement("UPDATE ejecucion_movimientos m
                JOIN (SELECT registro_id, MIN(id) AS primero FROM historial_cambios
                      WHERE tabla = 'ejecucion_movimientos' AND tipo_cambio = 'creacion'
                      GROUP BY registro_id) h ON h.registro_id = m.id
                JOIN historial_cambios hc ON hc.id = h.primero
                SET m.cargado_por = hc.usuario
                WHERE m.cargado_por IS NULL");
        }

        if (!Schema::hasTable('log_actividades')) {
            DB::statement('CREATE TABLE log_actividades (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fecha DATETIME NOT NULL,
                usuario VARCHAR(200) NOT NULL,
                accion VARCHAR(40) NOT NULL,
                tabla VARCHAR(60) NOT NULL,
                registro_id INT NULL,
                cuenta_operativa_id INT NULL,
                descripcion VARCHAR(1000) NULL,
                observacion TEXT NULL,
                KEY idx_la_fecha (fecha),
                KEY idx_la_registro (tabla, registro_id),
                KEY idx_la_cuenta (cuenta_operativa_id),
                KEY idx_la_usuario (usuario)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_actividades');

        if (Schema::hasColumn('ejecucion_movimientos', 'estado_aprobacion')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                DROP KEY idx_em_aprobacion,
                DROP COLUMN cambio_propuesto_fecha,
                DROP COLUMN cambio_propuesto_por,
                DROP COLUMN cambio_propuesto,
                DROP COLUMN aprobacion_observacion,
                DROP COLUMN aprobacion_fecha,
                DROP COLUMN aprobacion_usuario,
                DROP COLUMN cargado_por,
                DROP COLUMN estado_aprobacion');
        }

        if (Schema::hasColumn('user_roles', 'es_aprobador')) {
            DB::statement('ALTER TABLE user_roles DROP COLUMN es_aprobador');
        }
    }
};
