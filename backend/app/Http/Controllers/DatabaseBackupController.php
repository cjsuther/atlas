<?php

namespace App\Http\Controllers;

use App\Exports\DatabaseBackupExport;
use App\Support\DatabaseBackupSchema;
use App\Support\ImportadorTablas;
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
    public function __construct(protected ImportadorTablas $importador) {}

    /** @var array<int, string> avisos propios del recorrido de tablas */
    private array $avisos = [];

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
                'avisos'  => array_merge($this->avisos, $this->importador->avisos()),
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

            if ($this->importador->seIgnora($table)) {
                $resumen[] = ['tabla' => $table, 'insertados' => 0, 'actualizados' => 0, 'omitida' => true];
                continue;
            }

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
            $omitidas = 0;
            $pkAutomatica = $this->importador->claveEsAutomatica($table, $pk);

            foreach ($matrix as $i => $cells) {
                $row = $this->mapRow($headers, $cells, $allowed);
                if ($row === null) {
                    continue; // fila vacía
                }

                try {
                    // Traduce el formato anterior, convierte fechas de Excel y
                    // completa lo que la tabla exige y el archivo no trae.
                    $row = $this->importador->prepararFila($table, $row, $allowed, $pk);

                    $pkValue = $row[$pk] ?? null;

                    // Sin clave primaria propia la fila no identifica a nada:
                    // se omite en lugar de pisar a las demás.
                    if (($pkValue === null || $pkValue === '') && !$pkAutomatica) {
                        $omitidas++;
                        continue;
                    }

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

            $resumen[] = [
                'tabla'        => $table,
                'insertados'   => $insertados,
                'actualizados' => $actualizados,
                'omitidas'     => $omitidas,
            ];
            if ($omitidas > 0) {
                $this->avisos[] = "{$table}: {$omitidas} fila(s) omitida(s) por no traer {$pk}.";
            }
            if ($table === 'user_roles' && $actualizados > 0) {
                // El upsert va por id: una fila del archivo puede caer sobre un
                // usuario que ya existía y cambiarle nombre y rol.
                $this->avisos[] = "user_roles: {$actualizados} usuario(s) existente(s) fueron "
                                . 'sobrescritos porque el archivo trae su mismo id. Verifique que '
                                . 'sigue habiendo un administrador de sistema con acceso.';
            }
        }
    }

    /**
     * Construye una fila asociativa columna→valor con las columnas permitidas
     * de la tabla más las del formato anterior. Convierte cadenas vacías en
     * null. Devuelve null si la fila está completamente vacía.
     *
     * @param  array<int, mixed>  $headers
     * @param  array<int, mixed>  $cells
     * @param  array<int, string> $allowed
     * @return array<string, mixed>|null
     */
    private function mapRow(array $headers, array $cells, array $allowed): ?array
    {
        // Además de las columnas de la tabla se conservan las del formato
        // anterior, porque el importador las necesita para traducirlas.
        $legadas = ['gerencia', 'gerencia_area'];
        $row = [];
        $hasValue = false;

        foreach ($headers as $idx => $col) {
            if (!is_string($col) || (!in_array($col, $allowed, true) && !in_array($col, $legadas, true))) {
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
