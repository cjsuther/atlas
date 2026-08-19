<?php

namespace App\Http\Controllers;

use App\Exports\FullExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /**
     * GET /api/export/excel — descarga en un único Excel multi-solapa lo que el
     * usuario puede ver. El recorte por Gerencia de Área lo aplica FullExport.
     */
    public function full(FullExport $export): BinaryFileResponse
    {
        $filename = 'atlas-export-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download($export, $filename);
    }
}
