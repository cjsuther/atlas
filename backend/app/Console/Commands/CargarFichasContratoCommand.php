<?php

namespace App\Console\Commands;

use App\Models\Contrato;
use App\Support\SectorTree;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use Throwable;

/**
 * Completa la ficha de cada contrato con los datos que llevaban sus
 * expedientes.
 *
 * Lee la solapa de expedientes del Excel exportado por el sistema —la que se
 * llamaba «Contratos Ejecucion»—, ubica el nodo por su camino en la estructura
 * (Gerencia de Área › Gerencia › Contrato) y resuelve los catálogos por su
 * nombre: tipo por sigla, estado por nombre, UVT por siglas, solicitante por
 * razón social y responsables por «Apellido, Nombre».
 *
 * La estructura puede tener contratos repetidos: mismo nombre bajo la misma
 * Gerencia, cada uno con su cuenta. Los expedientes de ese camino se reparten
 * entre ellos en orden —el primero al nodo de id más bajo— y se avisa, para
 * revisarlo. Si sobran expedientes, van al último nodo: manda el primero que
 * llegó y los datos en que los demás difieren se informan.
 *
 * Un contrato que ya tiene ficha no se toca, salvo con --pisar.
 */
class CargarFichasContratoCommand extends Command
{
    protected $signature = 'atlas:cargar-fichas-contrato
                            {archivo   : Excel exportado por el sistema, con la solapa de expedientes}
                            {--solapa= : Nombre de la solapa (por defecto, «Contratos Ejecucion»)}
                            {--pisar   : Reemplaza la ficha de los contratos que ya la tienen}
                            {--dry-run : Muestra lo que haría sin escribir nada}';

    protected $description = 'Pasa a la ficha de cada contrato los datos que llevaban sus expedientes.';

    /** Encabezado de la solapa => columna de la ficha, para los datos que van tal cual. */
    private const TEXTOS = [
        'Descripción'       => 'descripcion_objeto',
        'Cliente'           => 'cliente',
        'Caja BAS'          => 'caja_bas',
        'Acta finalización' => 'acta_finalizacion',
        'Observaciones'     => 'observaciones',
    ];

    private const FECHAS = [
        'F. Inicio'       => 'fecha_inicio',
        'F. Vencimiento'  => 'fecha_vencimiento',
        'F. Finalización' => 'fecha_finalizacion',
    ];

    /** Columna de la solapa de la que sale cada dato de la ficha, para los avisos. */
    private const COLUMNA_DE = [
        'tipo_contrato_id' => 'Tipo', 'estado_id' => 'Estado', 'uvt_id' => 'UVT',
        'solicitante_id' => 'Solicitante', 'resp1_id' => 'Resp. 1', 'resp2_id' => 'Resp. 2',
        'moneda' => 'Moneda', 'cotizacion' => 'Cotización',
        'prorroga' => 'Prórroga', 'renovacion_automatica' => 'Renov. autom.',
        'descripcion_objeto' => 'Descripción', 'cliente' => 'Cliente', 'caja_bas' => 'Caja BAS',
        'acta_finalizacion' => 'Acta finalización', 'observaciones' => 'Observaciones',
        'fecha_inicio' => 'F. Inicio', 'fecha_vencimiento' => 'F. Vencimiento',
        'fecha_finalizacion' => 'F. Finalización',
    ];

    private const MONEDAS = [
        'peso' => 'Peso', 'pesos' => 'Peso', 'ars' => 'Peso',
        'dólar' => 'Dólar', 'dolar' => 'Dólar', 'dólares' => 'Dólar', 'dolares' => 'Dólar', 'usd' => 'Dólar',
        'euro' => 'Euro', 'euros' => 'Euro',
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
            $filas = $this->leer($ruta, (string) ($this->option('solapa') ?: 'Contratos Ejecucion'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $nodos     = $this->nodosPorCamino($arbol);
        $catalogos = $this->catalogos();

        /** @var array<int, array{ficha: array<string, mixed>, fila: int, origen: array<string, mixed>}> $fichas sector_id => ficha */
        $fichas    = [];
        $sinNodo   = [];

        /** @var array<string, int> camino => expedientes ya ubicados en él */
        $usados = [];

        foreach ($filas as $n => $f) {
            $camino = $this->clave($f['Gerencia de Área'] ?? null, $f['Gerencia'] ?? null, $f['Contrato'] ?? null);
            $nodo   = null;
            if (isset($nodos[$camino])) {
                $i    = $usados[$camino] = ($usados[$camino] ?? -1) + 1;
                $nodo = $nodos[$camino][min($i, count($nodos[$camino]) - 1)];
                if (count($nodos[$camino]) > 1) {
                    $this->avisos[] = "«{$arbol->rutaDe($nodo)}» está repetido en la estructura (ids "
                        . implode(', ', $nodos[$camino]) . '): sus expedientes se repartieron en orden. Revíselo.';
                }
            }
            if ($nodo === null) {
                $sinNodo[] = "fila {$n}: " . implode(' › ', array_filter([
                    $f['Gerencia de Área'] ?? null, $f['Gerencia'] ?? null, $f['Contrato'] ?? null,
                ]));
                continue;
            }

            $ficha = $this->ficha($f, $n, $catalogos);

            if (!isset($fichas[$nodo])) {
                $fichas[$nodo] = ['ficha' => $ficha, 'fila' => $n, 'origen' => $f];
                continue;
            }

            // Otro expediente del mismo contrato: manda el primero.
            $primera = $fichas[$nodo];
            $difieren = [];
            foreach ($ficha as $campo => $valor) {
                if ($valor !== null && $primera['ficha'][$campo] !== null && $valor != $primera['ficha'][$campo]) {
                    // Se muestra lo que dice el Excel, no el id del catálogo.
                    $col = self::COLUMNA_DE[$campo] ?? $campo;
                    $difieren[] = "{$col} ({$primera['origen'][$col]} / {$f[$col]})";
                } elseif ($valor !== null && $primera['ficha'][$campo] === null) {
                    $fichas[$nodo]['ficha'][$campo] = $valor; // completa lo que el primero no traía
                }
            }
            if ($difieren) {
                $this->avisos[] = "«{$arbol->rutaDe($nodo)}»: las filas {$primera['fila']} y {$n} difieren en "
                                . implode(', ', $difieren) . '. Quedó la fila ' . $primera['fila'] . '.';
            }
        }

        $existentes = Contrato::whereIn('sector_id', array_keys($fichas) ?: [0])->pluck('sector_id')
            ->map(fn ($id) => (int) $id)->flip();
        $pisar = (bool) $this->option('pisar');

        $crear = $reemplazar = $salteadas = 0;
        foreach (array_keys($fichas) as $nodo) {
            if (!isset($existentes[$nodo])) {
                $crear++;
            } elseif ($pisar) {
                $reemplazar++;
            } else {
                $salteadas++;
            }
        }

        $this->line(sprintf('Filas leídas:               %5d', count($filas)));
        $this->line(sprintf('Contratos con datos:        %5d', count($fichas)));
        $this->line(sprintf('Fichas nuevas:              %5d', $crear));
        $this->line(sprintf('Fichas reemplazadas:        %5d', $reemplazar));
        $this->line(sprintf('Ya tenían ficha (sin tocar): %4d', $salteadas));

        if ($sinNodo) {
            $this->warn(count($sinNodo) . ' fila(s) no corresponden a ningún contrato de la estructura:');
            foreach ($sinNodo as $s) {
                $this->line("  {$s}");
            }
        }
        foreach (array_unique($this->avisos) as $a) {
            $this->warn("  {$a}");
        }

        if ($this->option('dry-run')) {
            $this->info('Simulación: no se escribió nada.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($fichas, $existentes, $pisar) {
            foreach ($fichas as $nodo => ['ficha' => $ficha]) {
                if (isset($existentes[$nodo]) && !$pisar) {
                    continue;
                }
                Contrato::updateOrCreate(['sector_id' => $nodo], $ficha);
            }
        });

        $this->info('Fichas cargadas.');
        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>> número de fila en el Excel => encabezado => valor
     */
    private function leer(string $ruta, string $solapa): array
    {
        $libro = IOFactory::load($ruta);
        $hoja  = $libro->getSheetByName($solapa);
        if ($hoja === null) {
            throw new \RuntimeException("El archivo no tiene la solapa «{$solapa}». "
                . 'Indique cuál usar con --solapa.');
        }

        $datos      = $hoja->toArray(null, true, false, false);
        $encabezado = array_map(fn ($c) => trim((string) $c), array_shift($datos) ?? []);

        foreach (['Gerencia de Área', 'Gerencia', 'Contrato'] as $col) {
            if (!in_array($col, $encabezado, true)) {
                throw new \RuntimeException("La solapa «{$solapa}» no tiene la columna «{$col}», "
                    . 'que es la que ubica al contrato en la estructura.');
            }
        }

        $filas = [];
        foreach ($datos as $i => $fila) {
            $asoc = [];
            foreach ($encabezado as $j => $col) {
                if ($col !== '') {
                    $v = $fila[$j] ?? null;
                    $asoc[$col] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
                }
            }
            if (array_filter($asoc, fn ($v) => $v !== null)) {
                $filas[$i + 2] = $asoc; // +2: encabezado y base 0
            }
        }

        return $filas;
    }

    /** @return array<string, array<int>> camino normalizado => sector_id de menor a mayor */
    private function nodosPorCamino(SectorTree $arbol): array
    {
        $out = [];
        foreach ($arbol->nodosDelNivel('contrato') as $id) {
            $r = $arbol->ancestrosPorNivel($id);
            $out[$this->clave($arbol->nombre($r['gerencia_area']), $arbol->nombre($r['gerencia']), $arbol->nombre($id))][] = $id;
        }
        foreach ($out as &$ids) {
            sort($ids);
        }
        return $out;
    }

    private function clave(?string ...$partes): string
    {
        return implode('|', array_map(fn ($p) => mb_strtolower(trim((string) $p)), $partes));
    }

    /** @return array<string, array<string, int>> catálogo => nombre normalizado => id */
    private function catalogos(): array
    {
        $indice = fn ($filas, $clave, $id) => collect($filas)
            ->mapWithKeys(fn ($f) => [mb_strtolower(trim((string) $f->{$clave})) => (int) $f->{$id}])
            ->all();

        return [
            'tipo'        => $indice(DB::table('tipo_contrato_ejecucion')->get(['id', 'sigla']), 'sigla', 'id'),
            'estado'      => $indice(DB::table('estado_ejecucion')->get(['id', 'nombre']), 'nombre', 'id'),
            'uvt'         => $indice(DB::table('uvt')->get(['uvt_id', 'siglas']), 'siglas', 'uvt_id'),
            'solicitante' => $indice(DB::table('solicitantes')->get(['solicitante_id', 'razon_social']), 'razon_social', 'solicitante_id'),
            'personal'    => collect(DB::table('personal')->get(['legajo', 'apellido', 'nombre']))
                ->mapWithKeys(fn ($p) => [mb_strtolower(trim("{$p->apellido}, {$p->nombre}")) => (int) $p->legajo])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, array<string, int>>  $cat
     * @return array<string, mixed>
     */
    private function ficha(array $f, int $n, array $cat): array
    {
        $buscar = function (string $catalogo, string $columna) use ($f, $n, $cat): ?int {
            $valor = $f[$columna] ?? null;
            if ($valor === null) {
                return null;
            }
            $id = $cat[$catalogo][mb_strtolower(trim((string) $valor))] ?? null;
            if ($id === null) {
                $this->avisos[] = "fila {$n}: «{$columna}» = «{$valor}» no está en el catálogo; quedó vacío.";
            }
            return $id;
        };

        $ficha = [
            'tipo_contrato_id' => $buscar('tipo', 'Tipo'),
            'estado_id'        => $buscar('estado', 'Estado'),
            'uvt_id'           => $buscar('uvt', 'UVT'),
            'solicitante_id'   => $buscar('solicitante', 'Solicitante'),
            'resp1_id'         => $buscar('personal', 'Resp. 1'),
            'resp2_id'         => $buscar('personal', 'Resp. 2'),
        ];

        foreach (self::TEXTOS as $col => $campo) {
            $ficha[$campo] = isset($f[$col]) ? (string) $f[$col] : null;
        }
        foreach (self::FECHAS as $col => $campo) {
            $ficha[$campo] = $this->fecha($f[$col] ?? null, $n, $col);
        }

        $ficha['prorroga']              = $this->siNo($f['Prórroga'] ?? null);
        $ficha['renovacion_automatica'] = $this->siNo($f['Renov. autom.'] ?? null);

        $moneda = $f['Moneda'] ?? null;
        $ficha['moneda'] = $moneda === null ? 'Peso' : (self::MONEDAS[mb_strtolower(trim((string) $moneda))] ?? null);
        if ($ficha['moneda'] === null) {
            $this->avisos[] = "fila {$n}: la moneda «{$moneda}» no es Peso, Dólar ni Euro; quedó en Peso.";
            $ficha['moneda'] = 'Peso';
        }
        $ficha['cotizacion'] = $ficha['moneda'] === 'Peso' || !is_numeric($f['Cotización'] ?? null)
            ? null
            : (float) $f['Cotización'];

        return $ficha;
    }

    private function fecha(mixed $v, int $n, string $col): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return FechaExcel::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        foreach (['d/m/Y', 'Y-m-d', 'Y-m-d H:i:s'] as $formato) {
            try {
                return Carbon::createFromFormat('!' . $formato, (string) $v)->format('Y-m-d');
            } catch (Throwable) {
                // se prueba el siguiente formato
            }
        }
        $this->avisos[] = "fila {$n}: «{$col}» = «{$v}» no es una fecha; quedó vacía.";
        return null;
    }

    private function siNo(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) $v)), ['sí', 'si', '1', 'true'], true);
    }
}
