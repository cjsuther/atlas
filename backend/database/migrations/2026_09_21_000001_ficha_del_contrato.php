<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha del contrato: los datos que el expediente dejó de llevar pasan al
 * Contrato, el tercer nivel de la estructura.
 *
 * Es una tabla 1:1 con el nodo (`sector_id` es la clave): el árbol sigue siendo
 * uno solo y la ficha sólo existe para los nodos de ese nivel. Al borrar el
 * nodo se va su ficha.
 *
 * El monto se guarda en su moneda. Si no es pesos se exige la cotización, que
 * es la que lo lleva a pesos para poder sumarlo con el resto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contratos')) {
            return;
        }

        DB::statement("CREATE TABLE contratos (
            sector_id              INT          NOT NULL PRIMARY KEY,
            tipo_contrato_id       INT          NULL,
            estado_id              INT          NULL,
            uvt_id                 INT          NULL,
            solicitante_id         INT          NULL,
            resp1_id               INT          NULL,
            resp2_id               INT          NULL,
            descripcion_objeto     TEXT         NULL,
            cliente                VARCHAR(300) NULL,
            caja_bas               VARCHAR(200) NULL,
            fecha_inicio           DATE         NULL,
            fecha_vencimiento      DATE         NULL,
            fecha_finalizacion     DATE         NULL,
            acta_finalizacion      VARCHAR(500) NULL,
            prorroga               TINYINT(1)   NOT NULL DEFAULT 0,
            renovacion_automatica  TINYINT(1)   NOT NULL DEFAULT 0,
            monto                  DECIMAL(18,2) NULL,
            moneda                 ENUM('Peso','Dólar','Euro') NOT NULL DEFAULT 'Peso',
            cotizacion             DECIMAL(18,4) NULL,
            observaciones          TEXT         NULL,
            created_at             TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_ct_tipo        (tipo_contrato_id),
            KEY idx_ct_estado      (estado_id),
            KEY idx_ct_uvt         (uvt_id),
            KEY idx_ct_solicitante (solicitante_id),
            KEY idx_ct_vencimiento (fecha_vencimiento),

            CONSTRAINT fk_ct_sector FOREIGN KEY (sector_id)
                REFERENCES sector(sector_id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ct_tipo FOREIGN KEY (tipo_contrato_id)
                REFERENCES tipo_contrato_ejecucion(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_ct_estado FOREIGN KEY (estado_id)
                REFERENCES estado_ejecucion(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_ct_uvt FOREIGN KEY (uvt_id)
                REFERENCES uvt(uvt_id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_ct_solic FOREIGN KEY (solicitante_id)
                REFERENCES solicitantes(solicitante_id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_ct_resp1 FOREIGN KEY (resp1_id)
                REFERENCES personal(legajo) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_ct_resp2 FOREIGN KEY (resp2_id)
                REFERENCES personal(legajo) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos');
    }
};
