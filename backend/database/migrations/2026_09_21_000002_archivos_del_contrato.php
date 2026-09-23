<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos adjuntos de cada contrato: el convenio firmado, actas, anexos.
 *
 * Cuelgan del nodo del contrato, no de su ficha, para poder adjuntar antes de
 * completarla. El archivo vive en el disco privado del backend y se descarga
 * sólo por la API, con el mismo alcance que el contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contrato_archivos')) {
            return;
        }

        DB::statement("CREATE TABLE contrato_archivos (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            sector_id        INT          NOT NULL,
            nombre_original  VARCHAR(255) NOT NULL,
            ruta             VARCHAR(500) NOT NULL,
            mime             VARCHAR(150) NULL,
            tamano           BIGINT       NOT NULL DEFAULT 0,
            descripcion      VARCHAR(500) NULL,
            subido_por       VARCHAR(100) NULL,
            created_at       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_ca_sector (sector_id),

            CONSTRAINT fk_ca_sector FOREIGN KEY (sector_id)
                REFERENCES sector(sector_id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_archivos');
    }
};
