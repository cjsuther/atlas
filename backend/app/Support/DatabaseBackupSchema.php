<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Fuente única de verdad para el backup (export/import) de la base de datos.
 *
 * Define las tablas de negocio en orden de dependencia (padres antes que hijas),
 * su clave primaria y las columnas a excluir. Las columnas reales se obtienen
 * dinámicamente del esquema, de modo que el backup se adapta a cambios futuros.
 */
class DatabaseBackupSchema
{
    /**
     * Tablas incluidas en el backup, en orden de inserción seguro.
     *
     * @return array<int, array{table: string, pk: string, exclude: array<int, string>}>
     */
    public static function tables(): array
    {
        return [
            ['table' => 'tipo_contrato_principal', 'pk' => 'id',             'exclude' => []],
            ['table' => 'tipo_contrato_ejecucion', 'pk' => 'id',             'exclude' => []],
            ['table' => 'estado_principal',        'pk' => 'id',             'exclude' => []],
            ['table' => 'estado_ejecucion',        'pk' => 'id',             'exclude' => []],
            ['table' => 'solicitantes',            'pk' => 'solicitante_id', 'exclude' => []],
            ['table' => 'uvt',                     'pk' => 'uvt_id',         'exclude' => []],
            ['table' => 'sector',                  'pk' => 'sector_id',      'exclude' => []],
            ['table' => 'user_roles',              'pk' => 'id',             'exclude' => ['password']],
            ['table' => 'personal',                'pk' => 'legajo',         'exclude' => []],
            ['table' => 'contratos_principal',     'pk' => 'id',             'exclude' => []],
            ['table' => 'contratos_ejecucion',     'pk' => 'id',             'exclude' => []],
            ['table' => 'ejecucion_movimientos',   'pk' => 'id',             'exclude' => []],
        ];
    }

    /**
     * Columnas reales de una tabla (las del esquema menos las excluidas),
     * preservando el orden físico de la tabla.
     *
     * @param  array<int, string>  $exclude
     * @return array<int, string>
     */
    public static function columnsFor(string $table, array $exclude = []): array
    {
        $columns = Schema::getColumnListing($table);
        if (empty($exclude)) {
            return $columns;
        }
        return array_values(array_filter($columns, fn ($c) => !in_array($c, $exclude, true)));
    }
}
