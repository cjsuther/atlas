<?php

namespace App\Http\Controllers;

use App\Exports\DatabaseBackupExport;
use App\Support\DatabaseBackupSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    /**
     * GET /api/admin/db/export — backup técnico round-trip (una solapa por tabla).
     */
    public function export(): BinaryFileResponse
    {
        $filename = 'atlas-db-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new DatabaseBackupExport(), $filename);
    }

    /**
     * POST /api/admin/db/import — carga un Excel con la estructura del export.
     * Upsert por clave primaria (no borra lo que no esté en el archivo).
     */
    public function import(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'archivo' => ['required', 'file', 'mimes:xlsx,xls'],
        ])->validate();

        try {
            $spreadsheet = IOFactory::load($request->file('archivo')->getRealPath());
        } catch (Throwable $e) {
            return response()->json([
                'error'   => 'invalid_file',
                'message' => 'No se pudo leer el archivo Excel.',
            ], 422);
        }

        $resumen = [];

        try {
            DB::transaction(function () use ($spreadsheet, &$resumen) {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');

                try {
                $this->importTables($spreadsheet, $resumen);
                } finally {
                    // Restaurar siempre, incluso si una fila aborta la transacción.
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });
        } catch (Throwable $e) {
            return response()->json([
                'error'   => 'import_failed',
                'message' => 'No se pudo importar: ' . $e->getMessage() . ' (no se aplicó ningún cambio).',
            ], 422);
        }

        return response()->json([
            'data' => [
                'resumen' => $resumen,
            ],
        ]);
    }

    /**
     * Recorre las tablas en orden y aplica el upsert por clave primaria.
     *
     * @param  array<int, array<string, mixed>>  $resumen
     */
    private function importTables(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array &$resumen): void
    {
        foreach (DatabaseBackupSchema::tables() as $def) {
            $table = $def['table'];
            $pk    = $def['pk'];

            $sheet = $spreadsheet->getSheetByName($table);
            if (!$sheet) {
                $resumen[] = ['tabla' => $table, 'insertados' => 0, 'actualizados' => 0, 'omitida' => true];
                continue;
            }

            $allowed = DatabaseBackupSchema::columnsFor($table, $def['exclude']);
            $matrix  = $sheet->toArray(null, true, false, false);

            if (count($matrix) < 1) {
                $resumen[] = ['tabla' => $table, 'insertados' => 0, 'actualizados' => 0];
                continue;
            }

            $headers = array_map(fn ($h) => is_string($h) ? trim($h) : $h, array_shift($matrix));
            $insertados = 0;
            $actualizados = 0;

            foreach ($matrix as $i => $cells) {
                $row = $this->mapRow($headers, $cells, $allowed);
                if ($row === null) {
                    continue; // fila vacía
                }

                try {
                    $pkValue = $row[$pk] ?? null;
                    if ($pkValue !== null && $pkValue !== '') {
                        $exists = DB::table($table)->where($pk, $pkValue)->exists();
                        $attrs = $row;
                        unset($attrs[$pk]);
                        DB::table($table)->updateOrInsert([$pk => $pkValue], $attrs);
                        $exists ? $actualizados++ : $insertados++;
                    } else {
                        DB::table($table)->insert($row);
                        $insertados++;
                    }
                } catch (Throwable $e) {
                    // Número de fila en el Excel: +2 (encabezado + base 0)
                    throw new \RuntimeException(
                        "Error en la tabla \"{$table}\", fila " . ($i + 2) . ': ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            $resumen[] = ['tabla' => $table, 'insertados' => $insertados, 'actualizados' => $actualizados];
        }
    }

    /**
     * Construye una fila asociativa columna→valor, limitada a las columnas
     * permitidas de la tabla. Convierte cadenas vacías en null. Devuelve null
     * si la fila está completamente vacía.
     *
     * @param  array<int, mixed>  $headers
     * @param  array<int, mixed>  $cells
     * @param  array<int, string> $allowed
     * @return array<string, mixed>|null
     */
    private function mapRow(array $headers, array $cells, array $allowed): ?array
    {
        $row = [];
        $hasValue = false;

        foreach ($headers as $idx => $col) {
            if (!is_string($col) || !in_array($col, $allowed, true)) {
                continue;
            }
            $value = $cells[$idx] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '') {
                $value = null;
            }
            if ($value !== null) {
                $hasValue = true;
            }
            $row[$col] = $value;
        }

        return $hasValue ? $row : null;
    }
}
