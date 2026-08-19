<?php

namespace App\Console\Commands;

use App\Support\ImportadorTablas;
use App\Support\SectorTree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Carga la base a partir de un Excel con una solapa por tabla.
 *
 * Es el mismo archivo que acepta la pantalla de Exportar / Importar; la
 * diferencia es que por consola se puede reemplazar el contenido completo y
 * revisar el archivo antes de tocar nada. Las reglas de traducción están en
 * ImportadorTablas, compartidas por las dos vías.
 */
class ImportarLegacyCommand extends Command
{
    protected $signature = 'atlas:importar-legacy
                            {archivo      : Ruta al .xlsx a importar}
                            {--reemplazar : Vacía las tablas antes de cargar (import completo)}
                            {--dry-run    : Analiza el archivo y muestra el resumen sin escribir nada}';

    protected $description = 'Importa un Excel con una solapa por tabla, traduciendo el formato anterior al modelo de sectores.';

    /** Tablas en orden de dependencia: las padres antes que las hijas. */
    private const TABLAS = [
        'tipo_contrato_principal' => 'id',
        'tipo_contrato_ejecucion' => 'id',
        'estado_principal'        => 'id',
        'estado_ejecucion'        => 'id',
        'solicitantes'            => 'solicitante_id',
        'uvt'                     => 'uvt_id',
        'sector'                  => 'sector_id',
        'personal'                => 'legajo',
        'user_roles'              => 'id',
        'contratos_ejecucion'     => 'id',
        'ejecucion_movimientos'   => 'id',
    ];

    public function handle(ImportadorTablas $importador, SectorTree $arbol): int
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
        $faltantes = $this->sectoresFaltantes($hojas, $importador);
        if ($faltantes) {
            $this->error('Hay contratos que apuntan a sectores inexistentes en la solapa `sector`:');
            foreach ($faltantes as $id => $cantidad) {
                $this->line("  sector_id {$id}: {$cantidad} contrato(s)");
            }
            $this->line('Agregue esos sectores a la solapa `sector` y vuelva a importar.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->analizar($hojas, $importador);
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($hojas, $importador) {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                try {
                    if ($this->option('reemplazar')) {
                        $this->vaciar();
                    }
                    $this->cargar($hojas, $importador);
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });
        } catch (Throwable $e) {
            $this->error('La importación se revirtió: ' . $e->getMessage());
            return self::FAILURE;
        }

        $arbol->olvidar();

        foreach ($importador->avisos() as $aviso) {
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
            $datos = $libro->getSheetByName($nombre)->toArray(null, true, false, false);
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
    private function sectoresFaltantes(array $hojas, ImportadorTablas $importador): array
    {
        $sectores = [];
        foreach ($hojas['sector']['filas'] ?? [] as $f) {
            if (($id = $importador->entero($f['sector_id'] ?? null)) !== null) {
                $sectores[$id] = true;
            }
        }
        if (!$sectores) {
            return [];
        }

        $faltan = [];
        foreach ($hojas['contratos_ejecucion']['filas'] ?? [] as $f) {
            $sectorId = $importador->entero($f['gerencia'] ?? null)
                ?? $importador->entero($f['gerencia_area'] ?? null);
            if ($sectorId !== null && !isset($sectores[$sectorId])) {
                $faltan[$sectorId] = ($faltan[$sectorId] ?? 0) + 1;
            }
        }
        ksort($faltan);
        return $faltan;
    }

    /** @param array<string, array{filas: array<int, mixed>}> $hojas */
    private function analizar(array $hojas, ImportadorTablas $importador): void
    {
        $this->line('Contenido del archivo:');
        foreach (self::TABLAS as $tabla => $pk) {
            if ($importador->seIgnora($tabla)) {
                $this->line(sprintf('  %-24s se ignora (módulo retirado)', $tabla));
                continue;
            }
            $filas = count($hojas[$tabla]['filas'] ?? []);
            $sin   = 0;
            foreach ($hojas[$tabla]['filas'] ?? [] as $f) {
                if (($f[$pk] ?? null) === null) {
                    $sin++;
                }
            }
            $nota = $sin > 0 ? "  ({$sin} sin {$pk}, se omiten)" : '';
            $this->line(sprintf('  %-24s %5d fila(s)%s', $tabla, $filas, $nota));
        }
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
    private function cargar(array $hojas, ImportadorTablas $importador): void
    {
        foreach (self::TABLAS as $tabla => $pk) {
            if ($importador->seIgnora($tabla) || !Schema::hasTable($tabla) || !isset($hojas[$tabla])) {
                continue;
            }

            $columnas = Schema::getColumnListing($tabla);
            $insertar = [];
            $omitidas = 0;

            foreach ($hojas[$tabla]['filas'] as $i => $fila) {
                if (($fila[$pk] ?? null) === null) {
                    $omitidas++;
                    continue;
                }

                try {
                    $insertar[] = $importador->prepararFila($tabla, $fila, $columnas, $pk);
                } catch (Throwable $e) {
                    // Número de fila en el Excel: +2 (encabezado + base 0)
                    throw new \RuntimeException(
                        "tabla \"{$tabla}\", fila " . ($i + 2) . ': ' . $e->getMessage(), 0, $e
                    );
                }
            }

            // La inserción en lote toma las columnas de la primera fila.
            $insertar = $this->uniformar($insertar);

            foreach (array_chunk($insertar, 200) as $lote) {
                DB::table($tabla)->upsert($lote, [$pk]);
            }

            $this->line(sprintf('  %-24s %5d cargada(s)', $tabla, count($insertar)));
            if ($omitidas > 0) {
                $this->warn("  {$tabla}: {$omitidas} fila(s) omitida(s) por no traer {$pk}.");
            }
        }
    }

    /**
     * Iguala el juego de columnas de todas las filas, porque la inserción en
     * lote las toma de la primera.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function uniformar(array $filas): array
    {
        $columnas = [];
        foreach ($filas as $fila) {
            $columnas += array_flip(array_keys($fila));
        }
        $columnas = array_keys($columnas);

        return array_map(function ($fila) use ($columnas) {
            $out = [];
            foreach ($columnas as $c) {
                $out[$c] = $fila[$c] ?? null;
            }
            return $out;
        }, $filas);
    }
}
