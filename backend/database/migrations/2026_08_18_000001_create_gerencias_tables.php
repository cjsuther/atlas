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
     * Hasta ahora la gerencia del contrato era texto libre. Cada valor distinto
     * de `contratos_ejecucion.gerencia` (siglas del tipo GAEN#CNEA) se da de alta
     * como Gerencia de Área y, dentro de ella, como Gerencia homónima, de modo
     * que ningún contrato quede sin estructura al pasar al modelo relacional.
     *
     * La jerarquía queda plana a propósito: sólo la organización sabe qué
     * gerencias pertenecen realmente a cada Gerencia de Área, y eso se
     * consolida después desde la pantalla de Estructura.
     */
    private function backfillDesdeTextoLibre(): void
    {
        $nombres = collect();

        if (Schema::hasTable('contratos_ejecucion') && Schema::hasColumn('contratos_ejecucion', 'gerencia')) {
            $nombres = DB::table('contratos_ejecucion')
                ->select('gerencia')
                ->distinct()
                ->pluck('gerencia')
                ->map(fn ($g) => trim((string) $g))
                ->filter()
                ->unique();
        }

        // Destino de respaldo para los contratos sin gerencia cargada.
        $nombres = $nombres->push('Sin asignar')->unique()->values();

        foreach ($nombres as $nombre) {
            $nombre = mb_substr($nombre, 0, 200);
            $sigla  = $this->siglaDe($nombre);

            $areaId = DB::table('gerencias_area')->where('nombre', $nombre)->value('id')
                ?? DB::table('gerencias_area')->insertGetId([
                    'nombre'     => $nombre,
                    'sigla'      => $sigla,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $existe = DB::table('gerencias')
                ->where('gerencia_area_id', $areaId)
                ->where('nombre', $nombre)
                ->exists();
            if (!$existe) {
                DB::table('gerencias')->insert([
                    'gerencia_area_id' => $areaId,
                    'nombre'           => $nombre,
                    'sigla'            => $sigla,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    /**
     * Sigla legible a partir del nombre: `GAEN#CNEA` -> `GAEN`.
     * Se descarta si no es única, porque la columna lo exige.
     */
    private function siglaDe(string $nombre): ?string
    {
        $sigla = mb_substr(trim(explode('#', $nombre)[0]), 0, 50);
        if ($sigla === '' || $sigla === $nombre) {
            return null;
        }
        return DB::table('gerencias_area')->where('sigla', $sigla)->exists() ? null : $sigla;
    }

    public function down(): void
    {
        Schema::dropIfExists('gerencias');
        Schema::dropIfExists('gerencias_area');
    }
};
