<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use RuntimeException;

/**
 * Reglas comunes para cargar un Excel con una solapa por tabla.
 *
 * Las usan tanto la pantalla de Exportar / Importar como el comando
 * `atlas:importar-legacy`, para que un mismo archivo se comporte igual por
 * cualquiera de las dos vías.
 *
 * Se ocupa de tres cosas que el archivo no trae resueltas:
 *
 *   1. Traducir el formato anterior al modelo actual: en él, el contrato traía
 *      `gerencia_area` con el id del sector raíz y `gerencia` con el del
 *      subsector, y los roles eran admin / operador / consulta.
 *   2. Convertir fechas, que Excel entrega como número de serie, y normalizar
 *      los nombres de moneda.
 *   3. Completar las columnas que la tabla exige y el archivo trae vacías, sin
 *      inventar contenido y dejando aviso de lo que quedó por completar.
 */
class ImportadorTablas
{
    /**
     * Solapas que se ignoran: la gestión de contratos principales fue
     * reemplazada por la estructura de sectores, y sus filas duplican los
     * saldos iniciales que ya vienen en `contratos_ejecucion`.
     */
    public const IGNORADAS = ['contratos_principal'];

    /** Nombres de moneda del formato anterior. */
    private const MONEDAS = [
        'pesos' => 'Peso',  'peso' => 'Peso',  'ars' => 'Peso',
        'dolares' => 'Dólar', 'dólares' => 'Dólar', 'dolar' => 'Dólar',
        'dólar' => 'Dólar', 'usd' => 'Dólar',
        'euros' => 'Euro',  'euro' => 'Euro',
    ];

    /** Equivalencia de los roles anteriores con los actuales. */
    private const ROLES = [
        'admin'    => 'admin_sistema',
        'operador' => 'operador_gerencia',
        'consulta' => 'operador_gerencia',
    ];

    /** @var array<string, array<string, array{tipo: string, admite_null: bool, default: ?string}>> */
    private array $meta = [];

    /** @var array<string, array<int, string>> */
    private array $foraneas = [];

    /** @var array<int, string> */
    private array $avisos = [];

    /** @return array<int, string> */
    public function avisos(): array
    {
        return array_values(array_unique($this->avisos));
    }

    public function seIgnora(string $tabla): bool
    {
        return in_array($tabla, self::IGNORADAS, true);
    }

    /**
     * Deja una fila lista para insertar en la tabla indicada.
     *
     * @param  array<string, mixed>  $fila
     * @param  array<int, string>    $columnas  columnas admitidas de la tabla
     * @param  string|null           $pk        clave primaria, que nunca se completa sola
     * @return array<string, mixed>
     */
    public function prepararFila(string $tabla, array $fila, array $columnas, ?string $pk = null): array
    {
        $fila = $this->traducir($tabla, $fila);
        $fila = array_intersect_key($fila, array_flip($columnas));
        $fila = $this->convertirFechas($tabla, $fila);

        return $this->completar($tabla, $fila, $pk);
    }

    /**
     * Una clave primaria que el motor no genera sola no se puede inventar: sin
     * ella la fila no identifica a nada y pisaría a las demás.
     */
    public function claveEsAutomatica(string $tabla, string $pk): bool
    {
        $fila = DB::select(
            'SELECT EXTRA FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $pk]
        );
        return isset($fila[0]) && str_contains(strtolower($fila[0]->EXTRA), 'auto_increment');
    }

    // ------------------------------------------------------------------
    // Traducción del formato anterior
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function traducir(string $tabla, array $fila): array
    {
        if ($tabla === 'contratos_ejecucion') {
            // `gerencia` traía el id del subsector y `gerencia_area` el de la raíz.
            if (!array_key_exists('sector_id', $fila) || $fila['sector_id'] === null) {
                $fila['sector_id'] = $this->entero($fila['gerencia'] ?? null)
                    ?? $this->entero($fila['gerencia_area'] ?? null);
            }
            unset($fila['gerencia'], $fila['gerencia_area']);

            // Los contratos principales no se importan: el módulo fue retirado.
            $fila['contrato_principal_id'] = null;
        }

        if ($tabla === 'user_roles') {
            $rol = $fila['rol'] ?? null;
            if (is_string($rol) && isset(self::ROLES[$rol])) {
                $fila['rol'] = self::ROLES[$rol];
                $this->avisos[] = 'user_roles: los roles anteriores se convirtieron a los actuales. '
                                . 'Falta asignar a cada usuario su Gerencia de Área.';
            }
            // El alcance lo define la organización, no el archivo.
            $fila['sector_id'] = $fila['sector_id'] ?? null;
        }

        if ($tabla === 'ejecucion_movimientos') {
            // En este formato todos los movimientos son facturas.
            $fila['accion'] = $fila['accion'] ?? 'factura';
            $fila['contraparte_tipo'] = $fila['contraparte_tipo']
                ?? (($fila['tipo'] ?? null) === 'ingreso' ? 'cliente' : 'proveedor');
        }

        if (isset($fila['moneda']) && is_string($fila['moneda'])) {
            $clave = mb_strtolower(trim($fila['moneda']));
            $fila['moneda'] = self::MONEDAS[$clave] ?? $fila['moneda'];
        }

        return $fila;
    }

    public function entero(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (int) $v : null;
    }

    // ------------------------------------------------------------------
    // Fechas y columnas obligatorias
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function convertirFechas(string $tabla, array $fila): array
    {
        foreach ($this->metaDe($tabla) as $columna => $info) {
            if (!$this->esFecha($info['tipo']) || !array_key_exists($columna, $fila)) {
                continue;
            }
            $valor = $fila[$columna];
            if ($valor === null || $valor === '') {
                $fila[$columna] = null;
            } elseif (is_numeric($valor)) {
                // Excel entrega las fechas como número de serie.
                $fila[$columna] = FechaExcel::excelToDateTimeObject((float) $valor)->format('Y-m-d H:i:s');
            }
        }
        return $fila;
    }

    /**
     * Completa las columnas que no admiten nulos. Si la tabla tiene un valor
     * por defecto se usa ése; si no, uno neutro del tipo. Nunca se completa
     * una clave foránea: apuntar a un registro inexistente rompería el vínculo,
     * así que en ese caso se corta con un mensaje que dice qué falta.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function completar(string $tabla, array $fila, ?string $pk = null): array
    {
        foreach ($this->metaDe($tabla) as $columna => $info) {
            if (!array_key_exists($columna, $fila) || $fila[$columna] !== null) {
                continue;
            }

            // La clave primaria identifica la fila: se deja como está para que
            // el llamador decida si la omite.
            if ($columna === $pk) {
                continue;
            }

            // Si el archivo no trae el dato y la tabla tiene un valor por
            // defecto, manda el de la tabla. Es lo que pasa con created_at y
            // updated_at: guardarlos en nulo dejaba a los registros fuera de
            // cualquier filtro por fecha.
            if ($info['admite_null']) {
                if ($info['default'] === null) {
                    continue;
                }
                $fila[$columna] = str_contains(strtoupper($info['default']), 'CURRENT_TIMESTAMP')
                    ? now()
                    : $info['default'];
                continue;
            }

            if (in_array($columna, $this->foraneasDe($tabla), true)) {
                throw new RuntimeException(
                    "la columna \"{$columna}\" es obligatoria y referencia a otra tabla, "
                    . 'pero el archivo la trae vacía. Complete ese dato en el Excel antes de importar.'
                );
            }

            if ($info['default'] !== null) {
                $fila[$columna] = str_contains(strtoupper($info['default']), 'CURRENT_TIMESTAMP')
                    ? now()
                    : $info['default'];
                continue;
            }

            $fila[$columna] = match (true) {
                $this->esNumero($info['tipo']) => 0,
                $this->esFecha($info['tipo'])  => now(),
                default                        => '',
            };

            $this->avisos[] = "{$tabla}.{$columna}: el archivo trae filas sin este dato obligatorio; "
                            . 'se cargaron vacías para no perder el registro.';
        }

        return $fila;
    }

    private function esFecha(string $tipo): bool
    {
        return in_array($tipo, ['date', 'datetime', 'timestamp'], true);
    }

    private function esNumero(string $tipo): bool
    {
        foreach (['int', 'decimal', 'double', 'float'] as $n) {
            if (str_contains($tipo, $n)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Metadatos
    // ------------------------------------------------------------------

    /** @return array<string, array{tipo: string, admite_null: bool, default: ?string}> */
    public function metaDe(string $tabla): array
    {
        if (isset($this->meta[$tabla])) {
            return $this->meta[$tabla];
        }

        $out = [];
        foreach (DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$tabla]
        ) as $f) {
            $out[$f->COLUMN_NAME] = [
                'tipo'        => $f->DATA_TYPE,
                'admite_null' => $f->IS_NULLABLE === 'YES',
                'default'     => $f->COLUMN_DEFAULT,
            ];
        }

        return $this->meta[$tabla] = $out;
    }

    /** @return array<int, string> */
    public function foraneasDe(string $tabla): array
    {
        if (isset($this->foraneas[$tabla])) {
            return $this->foraneas[$tabla];
        }

        $filas = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$tabla]
        );

        return $this->foraneas[$tabla] = array_map(fn ($f) => $f->COLUMN_NAME, $filas);
    }
}
