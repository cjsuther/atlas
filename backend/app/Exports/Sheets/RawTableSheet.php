<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Solapa técnica de una tabla para el backup round-trip.
 *
 * Vuelca filas crudas (sin Eloquent ni accessors): el encabezado son los nombres
 * reales de columna y los valores se escriben SIEMPRE como texto, para que las
 * fechas y los números largos (CUIT, expedientes) sobrevivan a la reimportación
 * sin que PhpSpreadsheet los reinterprete.
 */
class RawTableSheet extends DefaultValueBinder implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize, WithCustomValueBinder, WithStrictNullComparison
{
    /**
     * @param  array<int, string>            $columns
     * @param  array<int, array<int, mixed>> $rows
     */
    public function __construct(
        private string $table,
        private array $columns,
        private array $rows,
    ) {}

    public function title(): string
    {
        return mb_substr($this->table, 0, 31);
    }

    public function headings(): array
    {
        return $this->columns;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1A2E4A'],
                ],
            ],
        ];
    }

    /**
     * Fuerza todo valor escalar no nulo a texto, preservando el formato exacto.
     */
    public function bindValue(Cell $cell, $value): bool
    {
        if ($value !== null && $value !== '') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
