<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura organizativa que reemplaza a la gestión de contratos principales:
 *
 *   Gerencia de Área -> Gerencia -> Contrato -> Movimiento de ejecución
 *
 * La Gerencia de Área es el límite de confidencialidad: los saldos y registros
 * de una Gerencia de Área no pueden verse desde otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gerencias_area')) {
            DB::statement("
                CREATE TABLE gerencias_area (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  sigla       VARCHAR(50)  NULL,
                  nombre      VARCHAR(200) NOT NULL,
                  responsable VARCHAR(200) NULL,
                  activo      TINYINT(1)   NOT NULL DEFAULT 1,
                  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                  updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_ga_nombre (nombre),
                  UNIQUE KEY uq_ga_sigla  (sigla)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!Schema::hasTable('gerencias')) {
            DB::statement("
                CREATE TABLE gerencias (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  gerencia_area_id INT          NOT NULL,
                  sigla            VARCHAR(50)  NULL,
                  nombre           VARCHAR(200) NOT NULL,
                  responsable      VARCHAR(200) NULL,
                  activo           TINYINT(1)   NOT NULL DEFAULT 1,
                  created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                  updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_ger_area_nombre (gerencia_area_id, nombre),
                  KEY idx_ger_area (gerencia_area_id),
                  CONSTRAINT fk_ger_area
                    FOREIGN KEY (gerencia_area_id) REFERENCES gerencias_area(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $this->backfillDesdeTextoLibre();
    }

    /**
     * Hasta ahora `gerencia_area` y `gerencia` eran texto libre en los contratos.
     * Se da de alta un registro de catálogo por cada combinación distinta que
     * exista en contratos_principal y contratos_ejecucion, de modo que ningún
     * dato cargado se pierda al pasar al modelo relacional.
     */
    private function backfillDesdeTextoLibre(): void
    {
        $pares = collect();

        foreach (['contratos_principal', 'contratos_ejecucion'] as $tabla) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'gerencia')) {
                continue;
            }
            $pares = $pares->merge(
                DB::table($tabla)
                    ->select('gerencia_area', 'gerencia')
                    ->distinct()
                    ->get()
                    ->map(fn ($r) => [
                        'area'     => trim((string) ($r->gerencia_area ?? '')),
                        'gerencia' => trim((string) ($r->gerencia ?? '')),
                    ])
            );
        }

        // Siempre existe un destino de respaldo para los contratos sin gerencia.
        $pares->push(['area' => '', 'gerencia' => '']);

        $areasPorNombre = [];
        foreach ($pares->unique(fn ($p) => $p['area'] . '||' . $p['gerencia']) as $par) {
            $nombreArea     = $par['area']     !== '' ? $par['area']     : 'Sin asignar';
            $nombreGerencia = $par['gerencia'] !== '' ? $par['gerencia'] : 'Sin asignar';

            if (!isset($areasPorNombre[$nombreArea])) {
                $areasPorNombre[$nombreArea] = DB::table('gerencias_area')
                    ->where('nombre', $nombreArea)->value('id')
                    ?? DB::table('gerencias_area')->insertGetId([
                        'nombre'     => $nombreArea,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $areaId = $areasPorNombre[$nombreArea];

            $existe = DB::table('gerencias')
                ->where('gerencia_area_id', $areaId)
                ->where('nombre', $nombreGerencia)
                ->exists();
            if (!$existe) {
                DB::table('gerencias')->insert([
                    'gerencia_area_id' => $areaId,
                    'nombre'           => $nombreGerencia,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gerencias');
        Schema::dropIfExists('gerencias_area');
    }
};
