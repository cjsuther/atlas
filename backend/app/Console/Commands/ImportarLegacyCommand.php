<?php

namespace App\Console\Commands;

use App\Support\SectorTree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use Throwable;

/**
 * Carga la base a partir de un Excel con una solapa por tabla.
 *
 * Es el formato con el que se venían entregando los datos, anterior a que la
 * estructura pasara a modelarse con `sector`: en él, `contratos_ejecucion`
 * trae `gerencia_area` con el id del sector raíz y `gerencia` con el del
 * subsector. Acá se traducen a `sector_id`, y los roles viejos a los nuevos.
 */
class ImportarLegacyCommand extends Command
{
    protected $signature = 'atlas:importar-legacy
                            {archivo               : Ruta al .xlsx a importar}
                            {--reemplazar          : Vacía las tablas antes de cargar (import completo)}
                            {--dry-run             : Analiza el archivo y muestra el resumen sin escribir nada}';

    protected $description = 'Importa un Excel con una solapa por tabla, traduciendo el formato anterior al modelo de sectores.';

    /** Tablas en orden de dependencia: las padres antes que las hijas. */
    private const TABLAS = [
        'tipo_contrato_principal' => 'id',
        'tipo_contrato_ejecucion' => 'id',
        'estado_principal'        => 'id',
        'estado_ejecucion'        => 'id',
        'solicitantes'            => 'solicitante_id',
        'utt'                     => 'utt_id',
        'uvt'                     => 'uvt_id',
        'sector'                  => 'sector_id',
        'personal'                => 'legajo',
        'user_roles'              => 'id',
        'contratos_ejecucion'     => 'id',
        'ejecucion_movimientos'   => 'id',
    ];

    /**
     * Solapas que se ignoran: la gestión de contratos principales fue
     * reemplazada por la estructura de sectores, y sus filas duplican los
     * saldos iniciales que ya vienen en `contratos_ejecucion`.
     */
    private const IGNORADAS = ['contratos_principal'];

    /** Nombres de moneda del formato anterior. */
    private const MONEDAS = [
        'pesos'    => 'Peso',
        'peso'     => 'Peso',
        'ars'      => 'Peso',
        'dolares'  => 'Dólar',
        'dólares'  => 'Dólar',
        'dolar'    => 'Dólar',
        'dólar'    => 'Dólar',
        'usd'      => 'Dólar',
        'euros'    => 'Euro',
        'euro'     => 'Euro',
    ];

    /** Equivalencia de los roles anteriores con los actuales. */
    private const ROLES = [
        'admin'    => 'admin_sistema',
        'operador' => 'operador_gerencia',
        'consulta' => 'operador_gerencia',
    ];

    /** @var array<int, string> */
    private array $avisos = [];

    public function handle(SectorTree $arbol): int
    {
        $ruta = (string) $this->argument('archivo');
        if (!is_readable($ruta)) {
            $this->error("No se puede leer el archivo: {$ruta}");
            return self::FAILURE;
        }

        try {
            $hojas = $this->leerHojas($ruta);
        } catch (Throwable $e) {
            $this->error('No se pudo leer el Excel: ' . $e->getMessage());
            return self::FAILURE;
        }

        // La estructura de sectores debe estar completa antes de vincular
        // contratos: si falta alguno, no se inventa.
        $faltantes = $this->sectoresFaltantes($hojas);
        if ($faltantes) {
            $this->error('Hay contratos que apuntan a sectores inexistentes en la solapa `sector`:');
            foreach ($faltantes as $id => $cantidad) {
                $this->line("  sector_id {$id}: {$cantidad} contrato(s)");
            }
            $this->line('Agregue esos sectores a la solapa `sector` y vuelva a importar.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->analizar($hojas);
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($hojas) {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                try {
                    if ($this->option('reemplazar')) {
                        $this->vaciar();
                    }
                    $this->cargar($hojas);
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });
        } catch (Throwable $e) {
            $this->error('La importación se revirtió: ' . $e->getMessage());
            return self::FAILURE;
        }

        $arbol->olvidar();

        foreach ($this->avisos as $aviso) {
            $this->warn('  ' . $aviso);
        }
        $this->info('Importación completada.');
        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{columnas: array<int, string>, filas: array<int, array<string, mixed>>}>
     */
    private function leerHojas(string $ruta): array
    {
        $libro = IOFactory::load($ruta);
        $out   = [];

        foreach ($libro->getSheetNames() as $nombre) {
            $hoja  = $libro->getSheetByName($nombre);
            $datos = $hoja->toArray(null, true, false, false);
            if (!$datos) {
                continue;
            }

            $encabezado = array_map(
                fn ($c) => is_string($c) ? trim($c) : (string) $c,
                array_shift($datos)
            );

            $filas = [];
            foreach ($datos as $fila) {
                $asoc = [];
                foreach ($encabezado as $i => $col) {
                    if ($col === '') {
                        continue;
                    }
                    $asoc[$col] = $this->normalizar($fila[$i] ?? null);
                }
                // Una fila totalmente vacía es relleno del Excel.
                if (array_filter($asoc, fn ($v) => $v !== null) !== []) {
                    $filas[] = $asoc;
                }
            }

            $out[$nombre] = ['columnas' => $encabezado, 'filas' => $filas];
        }

        return $out;
    }

    /** En este formato las celdas vacías vienen como espacio o cadena vacía. */
    private function normalizar(mixed $valor): mixed
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }
        if (is_string($valor)) {
            $valor = trim($valor);
            return $valor === '' ? null : $valor;
        }
        return $valor;
    }

    // ------------------------------------------------------------------
    // Validación previa
    // ------------------------------------------------------------------

    /**
     * Sectores referenciados por los contratos que no existen en la solapa.
     *
     * @param  array<string, array{filas: array<int, array<string, mixed>>}>  $hojas
     * @return array<int, int> sector_id => cantidad de contratos
     */
    private function sectoresFaltantes(array $hojas): array
    {
        $sectores = [];
        foreach ($hojas['sector']['filas'] ?? [] as $f) {
            if (($id = $this->entero($f['sector_id'] ?? null)) !== null) {
                $sectores[$id] = true;
            }
        }
        if (!$sectores) {
            return [];
        }

        $faltan = [];
        foreach ($hojas['contratos_ejecucion']['filas'] ?? [] as $f) {
            $sectorId = $this->sectorDeContrato($f);
            if ($sectorId !== null && !isset($sectores[$sectorId])) {
                $faltan[$sectorId] = ($faltan[$sectorId] ?? 0) + 1;
            }
        }
        ksort($faltan);
        return $faltan;
    }

    /** El subsector manda; si no vino, se usa la Gerencia de Área. */
    private function sectorDeContrato(array $fila): ?int
    {
        return $this->entero($fila['gerencia'] ?? null)
            ?? $this->entero($fila['gerencia_area'] ?? null);
    }

    private function entero(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (int) $v : null;
    }

    /** @param array<string, array{filas: array<int, mixed>}> $hojas */
    private function analizar(array $hojas): void
    {
        $this->line('Contenido del archivo:');
        foreach (self::TABLAS as $tabla => $pk) {
            $filas = count($hojas[$tabla]['filas'] ?? []);
            $sin   = $this->sinClave($hojas, $tabla, $pk);
            $nota  = $sin > 0 ? "  ({$sin} sin {$pk}, se omiten)" : '';
            $this->line(sprintf('  %-24s %5d fila(s)%s', $tabla, $filas, $nota));
        }
    }

    private function sinClave(array $hojas, string $tabla, string $pk): int
    {
        $n = 0;
        foreach ($hojas[$tabla]['filas'] ?? [] as $f) {
            if (($f[$pk] ?? null) === null) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    private function vaciar(): void
    {
        foreach (array_reverse(array_keys(self::TABLAS)) as $tabla) {
            if (Schema::hasTable($tabla)) {
                DB::table($tabla)->delete();
            }
        }
        DB::table('historial_cambios')->delete();
        $this->line('  Tablas vaciadas.');
    }

    /** @param array<string, array{filas: array<int, array<string, mixed>>}> $hojas */
    private function cargar(array $hojas): void
    {
        foreach (self::TABLAS as $tabla => $pk) {
            if (!Schema::hasTable($tabla) || !isset($hojas[$tabla])) {
                continue;
            }

            $meta   = $this->columnas($tabla);
            $fechas = $this->columnasDeFecha($tabla);

            // Todas las filas deben tener el mismo juego de columnas: la
            // inserción en lote las toma de la primera.
            $destino = array_values(array_intersect(
                array_keys($meta),
                array_keys($this->adaptar($tabla, $hojas[$tabla]['filas'][0] ?? []) + array_flip($hojas[$tabla]['columnas'] ?? []))
            ));

            $insertar    = [];
            $omitidas    = 0;
            $completadas = [];

            foreach ($hojas[$tabla]['filas'] as $fila) {
                $fila = $this->adaptar($tabla, $fila);

                if (($fila[$pk] ?? null) === null) {
                    $omitidas++;
                    continue;
                }

                $fila = $this->convertirFechas($fila, $fechas);

                $final = [];
                foreach ($destino as $columna) {
                    $final[$columna] = $this->valorPara(
                        $columna, $fila[$columna] ?? null, $meta, $completadas
                    );
                }
                $insertar[] = $final;
            }

            foreach ($completadas as $columna => $cantidad) {
                $this->avisos[] = "{$tabla}.{$columna}: {$cantidad} fila(s) sin valor en una columna obligatoria; "
                                . 'se cargaron vacías para no perder el registro.';
            }

            foreach (array_chunk($insertar, 200) as $lote) {
                DB::table($tabla)->upsert($lote, [$pk]);
            }

            $this->line(sprintf('  %-24s %5d cargada(s)', $tabla, count($insertar)));
            if ($omitidas > 0) {
                $this->avisos[] = "{$tabla}: {$omitidas} fila(s) omitida(s) por no traer {$pk}.";
            }
        }
    }

    /**
     * Columnas de fecha de la tabla. Excel las entrega como número de serie
     * cuando la celda tiene formato de fecha, así que hay que convertirlas.
     *
     * @return array<int, string>
     */
    private function columnasDeFecha(string $tabla): array
    {
        $filas = DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND DATA_TYPE IN ('date', 'datetime', 'timestamp')",
            [$tabla]
        );
        return array_map(fn ($f) => $f->COLUMN_NAME, $filas);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<int, string>    $fechas
     * @return array<string, mixed>
     */
    private function convertirFechas(array $fila, array $fechas): array
    {
        foreach ($fechas as $columna) {
            $valor = $fila[$columna] ?? null;
            if ($valor === null || $valor === '') {
                $fila[$columna] = null;
                continue;
            }
            if (is_numeric($valor)) {
                $fila[$columna] = FechaExcel::excelToDateTimeObject((float) $valor)->format('Y-m-d H:i:s');
            }
        }
        return $fila;
    }

    /**
     * Metadatos de las columnas de la tabla: hacen falta para decidir qué poner
     * cuando el Excel trae vacía una columna que no admite nulos.
     *
     * @return array<string, array{tipo: string, admite_null: bool, default: ?string}>
     */
    private function columnas(string $tabla): array
    {
        $filas = DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$tabla]
        );

        $out = [];
        foreach ($filas as $f) {
            $out[$f->COLUMN_NAME] = [
                'tipo'        => $f->DATA_TYPE,
                'admite_null' => $f->IS_NULLABLE === 'YES',
                'default'     => $f->COLUMN_DEFAULT,
            ];
        }
        return $out;
    }

    /**
     * Valor final de una celda. Si la columna no admite nulos, se usa el valor
     * por defecto de la tabla y, si no tiene, uno neutro del tipo: no se
     * inventa contenido, se deja vacío y se informa para poder completarlo
     * después desde la aplicación.
     *
     * @param  array<string, array{tipo: string, admite_null: bool, default: ?string}>  $meta
     * @param  array<string, int>  $completadas
     */
    private function valorPara(string $columna, mixed $valor, array $meta, array &$completadas): mixed
    {
        if ($valor !== null || ($meta[$columna]['admite_null'] ?? true)) {
            return $valor;
        }

        $tipo    = $meta[$columna]['tipo'] ?? 'varchar';
        $default = $meta[$columna]['default'];

        if ($default !== null) {
            return str_contains(strtoupper($default), 'CURRENT_TIMESTAMP') ? now() : $default;
        }

        $completadas[$columna] = ($completadas[$columna] ?? 0) + 1;

        return match (true) {
            str_contains($tipo, 'int'), str_contains($tipo, 'decimal'),
            str_contains($tipo, 'double'), str_contains($tipo, 'float') => 0,
            str_contains($tipo, 'date'), str_contains($tipo, 'time')    => now(),
            default                                                     => '',
        };
    }

    /**
     * Traduce una fila del formato anterior al modelo actual.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function adaptar(string $tabla, array $fila): array
    {
        if ($tabla === 'contratos_ejecucion') {
            // `gerencia` traía el id del subsector y `gerencia_area` el de la raíz.
            $fila['sector_id'] = $this->sectorDeContrato($fila);
            unset($fila['gerencia'], $fila['gerencia_area']);

            // Los contratos principales no se importan: el módulo fue retirado.
            $fila['contrato_principal_id'] = null;
        }

        if (isset($fila['moneda'])) {
            $clave = mb_strtolower(trim((string) $fila['moneda']));
            $fila['moneda'] = self::MONEDAS[$clave] ?? $fila['moneda'];
        }

        if ($tabla === 'user_roles') {
            $rol = $fila['rol'] ?? null;
            if (is_string($rol) && isset(self::ROLES[$rol])) {
                $fila['rol'] = self::ROLES[$rol];
            }
            // El alcance por Gerencia de Área lo asigna después el administrador.
            $fila['sector_id'] = null;
        }

        if ($tabla === 'ejecucion_movimientos') {
            // En este formato todos los movimientos son facturas.
            $fila['accion']           = $fila['accion'] ?? 'factura';
            $fila['contraparte_tipo'] = ($fila['tipo'] ?? null) === 'ingreso' ? 'cliente' : 'proveedor';
        }

        return $fila;
    }
}
