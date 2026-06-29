<?php

namespace App\Exports;

use App\Exports\Sheets\RawTableSheet;
use App\Support\DatabaseBackupSchema;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export técnico de toda la base de datos de negocio: una solapa por tabla,
 * con columnas crudas e IDs, lista para reimportarse.
 */
class DatabaseBackupExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $sheets = [];

        foreach (DatabaseBackupSchema::tables() as $def) {
            $columns = DatabaseBackupSchema::columnsFor($def['table'], $def['exclude']);

            $rows = DB::table($def['table'])
                ->get($columns)
                ->map(fn ($row) => array_map(
                    fn ($col) => $row->{$col} ?? null,
                    $columns
                ))
                ->all();

            $sheets[] = new RawTableSheet($def['table'], $columns, $rows);
        }

        return $sheets;
    }
}
