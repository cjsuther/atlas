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

    /**
     * Nombre que tenía la solapa en los archivos anteriores. Un export viejo
     * sigue entrando aunque la tabla se haya renombrado.
     */
    private const SOLAPAS_ANTERIORES = [
        'expedientes' => 'contratos_ejecucion',
    ];

    /** @var array<int, string> avisos propios del recorrido de tablas */
    private array $avisos = [];

    /**
     * Id de cada usuario en el archivo => id que quedó en la base. Difieren
     * cuando el usuario ya existía aquí con otro id, o cuando el id del archivo
     * lo ocupa otra persona.
     *
     * @var array<int|string, int>
     */
    private array $idsUsuario = [];

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
        $reconocidas = 0;

        foreach (DatabaseBackupSchema::tables() as $def) {
            $table = $def['table'];
            $pk    = $def['pk'];

            if ($this->importador->seIgnora($table)) {
                $resumen[] = ['tabla' => $table, 'insertados' => 0, 'actualizados' => 0, 'omitida' => true];
                continue;
            }

            $sheet = $spreadsheet->getSheetByName($table)
                ?: $spreadsheet->getSheetByName(self::SOLAPAS_ANTERIORES[$table] ?? '');
            if (!$sheet) {
                $resumen[] = ['tabla' => $table, 'insertados' => 0, 'actualizados' => 0, 'omitida' => true];
                continue;
            }

            $reconocidas++;

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

                    $idArchivo = $row[$pk] ?? null;
                    $row = match ($table) {
                        'user_roles'       => $this->ubicarUsuario($row),
                        'usuario_permisos' => $this->ubicarPermiso($row),
                        default            => $row,
                    };

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
                        unset($row[$pk]);
                        $pkValue = DB::table($table)->insertGetId($row, $pk);
                        $insertados++;
                    }

                    if ($table === 'user_roles' && $idArchivo !== null && $idArchivo !== '') {
                        $this->idsUsuario[$idArchivo] = (int) $pkValue;
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
                // Los usuarios se identifican por nombre de usuario: los que ya
                // existían toman los datos del archivo, incluido si son admin.
                $this->avisos[] = "user_roles: {$actualizados} usuario(s) existente(s) fueron "
                                . 'actualizados con los datos del archivo. Verifique que sigue '
                                . 'habiendo un administrador de sistema con acceso.';
            }
        }

        // Un archivo que no trae ninguna solapa con nombre de tabla no es un
        // backup: lo más común es confundirlo con el export legible del panel.
        if ($reconocidas === 0) {
            throw new \RuntimeException(
                'el archivo no tiene ninguna solapa con el nombre de una tabla '
                . '(por ejemplo "sector" o "expedientes"). Para importar hace falta el '
                . 'archivo de "Exportar base de datos"; el de "Exportar todo a Excel" es sólo para leer.'
            );
        }
    }

    /**
     * Un usuario se identifica por su nombre de usuario, no por el id: entre
     * dos instalaciones el mismo id puede ser otra persona. Si ya existe se
     * actualiza ése; si no, se conserva el id del archivo sólo cuando está
     * libre, y si no se le asigna uno nuevo.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function ubicarUsuario(array $row): array
    {
        $existente = isset($row['username'])
            ? DB::table('user_roles')->where('username', $row['username'])->value('id')
            : null;

        if ($existente !== null) {
            $row['id'] = $existente;
        } elseif (isset($row['id']) && DB::table('user_roles')->where('id', $row['id'])->exists()) {
            unset($row['id']);
        }

        return $row;
    }

    /**
     * El permiso apunta al id que el usuario tomó en esta base, y se reconoce
     * por usuario y nodo, que es lo que lo hace único.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function ubicarPermiso(array $row): array
    {
        $usuario = $row['user_role_id'] ?? null;
        if ($usuario !== null && isset($this->idsUsuario[$usuario])) {
            $row['user_role_id'] = $this->idsUsuario[$usuario];
        }

        $existente = DB::table('usuario_permisos')
            ->where('user_role_id', $row['user_role_id'] ?? null)
            ->where(fn ($q) => ($row['sector_id'] ?? null) === null
                ? $q->whereNull('sector_id')
                : $q->where('sector_id', $row['sector_id']))
            ->value('id');

        if ($existente !== null) {
            $row['id'] = $existente;
        } elseif (isset($row['id']) && DB::table('usuario_permisos')->where('id', $row['id'])->exists()) {
            unset($row['id']);
        }

        return $row;
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
        $legadas = ['gerencia', 'gerencia_area', 'rol',
                    'contrato_ejecucion_id', 'contrato_contraparte_id'];
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
