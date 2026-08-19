<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Jerarquía de sectores resuelta en memoria.
 *
 * La estructura organizativa vive en la tabla `sector`, que se referencia a sí
 * misma: los sectores sin dependencia son las Gerencias de Área y el resto son
 * sus subsectores.
 *
 *   Gerencia de Área (raíz)  ->  Subsector  ->  Contrato  ->  Movimiento
 *
 * La tabla es chica (decenas de filas) y se consulta muchas veces por request
 * —alcance del usuario, agrupaciones del panel—, así que se carga una sola vez
 * y los recorridos se hacen en PHP en lugar de con consultas recursivas.
 */
class SectorTree
{
    /** @var array<int, int|null> sector_id => dependencia_id */
    private array $padres = [];

    /** @var array<int, array<int>> dependencia_id => [sector_id, ...] */
    private array $hijos = [];

    /** @var array<int, string> sector_id => nombre */
    private array $nombres = [];

    private bool $cargado = false;

    private function cargar(): void
    {
        if ($this->cargado) {
            return;
        }

        foreach (DB::table('sector')->select('sector_id', 'dependencia_id', 'nombre')->get() as $s) {
            $id     = (int) $s->sector_id;
            $padre  = $s->dependencia_id !== null ? (int) $s->dependencia_id : null;

            $this->padres[$id]  = $padre;
            $this->nombres[$id] = (string) $s->nombre;
            $this->hijos[$padre ?? 0][] = $id;
        }

        $this->cargado = true;
    }

    /** Descarta lo cacheado; sólo necesario tras modificar la estructura. */
    public function olvidar(): void
    {
        $this->padres = $this->hijos = $this->nombres = [];
        $this->cargado = false;
    }

    /** Gerencias de Área: los sectores que no dependen de ningún otro. @return array<int> */
    public function raices(): array
    {
        $this->cargar();
        return $this->hijos[0] ?? [];
    }

    public function esRaiz(?int $sectorId): bool
    {
        if ($sectorId === null) {
            return false;
        }
        $this->cargar();
        return array_key_exists($sectorId, $this->padres) && $this->padres[$sectorId] === null;
    }

    public function existe(?int $sectorId): bool
    {
        if ($sectorId === null) {
            return false;
        }
        $this->cargar();
        return array_key_exists($sectorId, $this->padres);
    }

    public function nombre(?int $sectorId): ?string
    {
        if ($sectorId === null) {
            return null;
        }
        $this->cargar();
        return $this->nombres[$sectorId] ?? null;
    }

    public function padre(?int $sectorId): ?int
    {
        if ($sectorId === null) {
            return null;
        }
        $this->cargar();
        return $this->padres[$sectorId] ?? null;
    }

    /**
     * Gerencia de Área a la que pertenece un sector: su ancestro sin dependencia.
     * Un sector raíz es su propia Gerencia de Área.
     */
    public function raizDe(?int $sectorId): ?int
    {
        if ($sectorId === null) {
            return null;
        }
        $this->cargar();

        $visitados = [];
        $actual    = $sectorId;
        while ($actual !== null && array_key_exists($actual, $this->padres)) {
            if (isset($visitados[$actual])) {
                break; // ciclo en los datos: se corta en lugar de colgarse
            }
            $visitados[$actual] = true;

            $padre = $this->padres[$actual];
            if ($padre === null) {
                return $actual;
            }
            $actual = $padre;
        }

        return null;
    }

    /**
     * El sector y todos sus descendientes, para recortar consultas por alcance.
     *
     * @return array<int>
     */
    public function ramaDe(?int $sectorId): array
    {
        if ($sectorId === null) {
            return [];
        }
        $this->cargar();
        if (!array_key_exists($sectorId, $this->padres)) {
            return [];
        }

        $rama      = [];
        $pendiente = [$sectorId];
        while ($pendiente) {
            $actual = array_pop($pendiente);
            if (isset($rama[$actual])) {
                continue; // ya visitado: protege de ciclos
            }
            $rama[$actual] = true;
            foreach ($this->hijos[$actual] ?? [] as $hijo) {
                $pendiente[] = $hijo;
            }
        }

        return array_keys($rama);
    }

    /** Subsectores directos de un sector. @return array<int> */
    public function hijosDe(?int $sectorId): array
    {
        if ($sectorId === null) {
            return [];
        }
        $this->cargar();
        return $this->hijos[$sectorId] ?? [];
    }
}
