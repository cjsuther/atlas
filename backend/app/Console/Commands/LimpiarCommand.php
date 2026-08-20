<?php

namespace App\Console\Commands;

use App\Support\LimpiezaDatos;
use App\Support\SectorTree;
use Illuminate\Console\Command;

/**
 * Vacía los datos del sistema para volver a importarlos.
 *
 * A diferencia de la migración equivalente, se puede ejecutar las veces que
 * haga falta mientras se ajusta el archivo a importar.
 */
class LimpiarCommand extends Command
{
    protected $signature = 'atlas:limpiar
                            {--force              : No pedir confirmación}
                            {--conservar-facturas : Dejar los archivos de facturas en el disco}';

    protected $description = 'Vacía contratos, movimientos, estructura y catálogos para reimportar desde cero.';

    public function handle(SectorTree $arbol): int
    {
        $this->warn('Esto borra contratos, movimientos, historial, sectores, personal y catálogos.');
        $this->line('Se conservan los usuarios y sus sesiones, pero pierden la Gerencia de Área asignada.');

        if (!$this->option('force') && !$this->confirm('¿Continuar?', false)) {
            $this->info('Cancelado. No se borró nada.');
            return self::SUCCESS;
        }

        $borradas = LimpiezaDatos::ejecutar(!$this->option('conservar-facturas'));
        $arbol->olvidar();

        $this->newLine();
        foreach ($borradas as $tabla => $cantidad) {
            $this->line(sprintf('  %-32s %6d', $tabla, $cantidad));
        }
        $this->newLine();
        $this->info('Sistema vacío. Ya se puede importar.');
        $this->line('  php artisan atlas:importar-legacy archivo.xlsx --reemplazar');

        return self::SUCCESS;
    }
}
