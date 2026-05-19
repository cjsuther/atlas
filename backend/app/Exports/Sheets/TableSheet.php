<?php

namespace App\Exports\Sheets;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet genérica de Excel construida a partir de:
 *   - un título de solapa
 *   - una query Eloquent o función que la devuelve
 *   - un array de encabezados
 *   - una función mapper que recibe el row y devuelve array
 */
class TableSheet implements FromQuery, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    public function __construct(
        private string $title,
        private Builder|Closure $queryOrFactory,
        private array $headings,
        private Closure $mapper,
    ) {}

    public function title(): string
    {
        // Excel limita títulos a 31 caracteres y no permite \ / ? * [ ]
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]]/', ' ', $this->title);
        return mb_substr($clean, 0, 31);
    }

    public function query()
    {
        return $this->queryOrFactory instanceof Closure
            ? ($this->queryOrFactory)()
            : $this->queryOrFactory;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($row): array
    {
        return ($this->mapper)($row);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1A2E4A'],
                ],
            ],
        ];
    }
}
