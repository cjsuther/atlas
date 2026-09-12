<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas operativas.
 *
 * La estructura organizativa (`sector`) es un árbol de tres niveles:
 *
 *   Gerencia de Área  ->  Gerencia  ->  Contrato
 *
 * Una cuenta operativa cuelga de cualquier nodo de ese árbol, y un nodo puede
 * tener varias. `sector_id` en null es la cuenta de toda la organización: la
 * raíz del árbol, por encima de las Gerencias de Área.
 *
 * Los expedientes se imputan a una cuenta, y de ella se deduce a qué rama
 * pertenecen. Para que nada quede sin cuenta al migrar, se crea una por cada
 * nodo existente con el mismo nombre del nodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_operativas', function (Blueprint $table) {
            $table->integer('id', true); // INT con signo, como el resto del esquema
            $table->string('nombre', 200);
            $table->integer('sector_id')->nullable()
                  ->comment('Nodo del árbol del que cuelga. Null = toda la organización.');
            $table->string('descripcion', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('sector_id');
            $table->unique(['sector_id', 'nombre']);
            $table->foreign('sector_id')->references('sector_id')->on('sector')->onDelete('cascade');
        });

        // Una cuenta por nodo, con su mismo nombre.
        $ahora = now();
        $filas = DB::table('sector')->select('sector_id', 'nombre')->get()
            ->map(fn ($s) => [
                'nombre'     => $s->nombre,
                'sector_id'  => $s->sector_id,
                'activo'     => 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all();

        foreach (array_chunk($filas, 100) as $lote) {
            DB::table('cuentas_operativas')->insert($lote);
        }

        // Los expedientes pasan a imputarse a una cuenta. Cada uno arranca en
        // la cuenta homónima del nodo al que pertenecía.
        Schema::table('contratos_ejecucion', function (Blueprint $table) {
            $table->integer('cuenta_operativa_id')->nullable()->after('sector_id');
            $table->index('cuenta_operativa_id');
            $table->foreign('cuenta_operativa_id')->references('id')->on('cuentas_operativas');
        });

        DB::statement('
            UPDATE contratos_ejecucion c
              JOIN cuentas_operativas co ON co.sector_id = c.sector_id
               SET c.cuenta_operativa_id = co.id
             WHERE c.cuenta_operativa_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('contratos_ejecucion', function (Blueprint $table) {
            $table->dropForeign(['cuenta_operativa_id']);
            $table->dropColumn('cuenta_operativa_id');
        });

        Schema::dropIfExists('cuentas_operativas');
    }
};
