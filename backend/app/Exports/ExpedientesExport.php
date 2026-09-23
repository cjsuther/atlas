<?php

namespace App\Exports;

use App\Services\ExpedienteService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * La grilla de expedientes tal como se está viendo: su número, dónde está
 * imputado y lo que se movió contra él.
 */
class ExpedientesExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        protected array $filters,
        protected ExpedienteService $service,
    ) {}

    public function query()
    {
        return $this->service->buildQuery($this->filters);
    }

    public function title(): string
    {
        return 'Expedientes';
    }

    public function headings(): array
    {
        return [
            'ID', 'Expediente',
            'Gerencia de Área', 'Gerencia', 'Contrato',
            'Cuenta',
            'Ingresos relacionados', 'Gastos relacionados', 'Resultado',
            'Baja',
        ];
    }

    public function map($r): array
    {
        return [
            $r->id,
            $r->nro_expediente,
            $r->estructura['gerencia_area']['nombre'] ?? null,
            $r->estructura['gerencia']['nombre'] ?? null,
            $r->estructura['contrato']['nombre'] ?? null,
            optional($r->cuentaOperativa)->nombre,
            $r->monto_ejecutado_ingresos,
            $r->monto_ejecutado_gastos,
            $r->saldo,
            $r->deleted_at ? 'Sí' : 'No',
        ];
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
