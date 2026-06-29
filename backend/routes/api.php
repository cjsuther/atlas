<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContratoEjecucionController;
use App\Http\Controllers\ContratoPrincipalController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\EjecucionMovimientoController;
use App\Http\Controllers\EstadoEjecucionController;
use App\Http\Controllers\EstadoPrincipalController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\SolicitanteController;
use App\Http\Controllers\TipoContratoEjecucionController;
use App\Http\Controllers\TipoContratoPrincipalController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UttController;
use App\Http\Controllers\UvtController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - ATLAS v2
|--------------------------------------------------------------------------
| Prefijo: /api  (configurado en bootstrap/app.php)
| Roles:
|   admin    : acceso total
|   operador : GET + POST + PUT + DELETE en contratos; GET en panel/historial; GET en entidades
|   consulta : sólo GET sobre contratos y panel
*/

// ----------------------------------------------------------------------
// Healthcheck público
// ----------------------------------------------------------------------
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app'    => 'ATLAS',
    'time'   => now()->toIso8601String(),
]));

// ----------------------------------------------------------------------
// Autenticación
// ----------------------------------------------------------------------
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',     [AuthController::class, 'me']);

    // ------------------------------------------------------------------
    // Export consolidado de todas las tablas (todos los roles autenticados).
    // ------------------------------------------------------------------
    Route::get('/export/excel', [ExportController::class, 'full']);

    // ------------------------------------------------------------------
    // Panel de Control (todos los roles autenticados)
    // ------------------------------------------------------------------
    Route::prefix('panel')->group(function () {
        Route::get('/indicadores',  [PanelController::class, 'indicadores']);
        Route::get('/calculados',   [PanelController::class, 'calculados']);
        Route::get('/por-uvt',      [PanelController::class, 'porUvt']);
        Route::get('/por-gerencia', [PanelController::class, 'porGerencia']);
        Route::get('/por-tipo',     [PanelController::class, 'porTipo']);
        Route::get('/por-estado',   [PanelController::class, 'porEstado']);
        Route::get('/por-moneda',   [PanelController::class, 'porMoneda']);
        Route::get('/vencimientos', [PanelController::class, 'vencimientos']);
        Route::get('/rankings',     [PanelController::class, 'rankings']);
    });

    // ------------------------------------------------------------------
    // Contratos Principal
    //   GET   : todos los roles
    //   POST/PUT/DELETE : admin + operador (delete = baja lógica)
    //   export: todos los roles
    // ------------------------------------------------------------------
    Route::get('/contratos-principal',              [ContratoPrincipalController::class, 'index']);
    Route::get('/contratos-principal/export/excel', [ContratoPrincipalController::class, 'export']);
    Route::get('/contratos-principal/{id}',         [ContratoPrincipalController::class, 'show'])->whereNumber('id');

    Route::middleware('role:admin,operador')->group(function () {
        Route::post('/contratos-principal',           [ContratoPrincipalController::class, 'store']);
        Route::put('/contratos-principal/{id}',       [ContratoPrincipalController::class, 'update'])->whereNumber('id');
        Route::delete('/contratos-principal/{id}',    [ContratoPrincipalController::class, 'destroy'])->whereNumber('id');
    });

    // ------------------------------------------------------------------
    // Contratos de Ejecución
    // ------------------------------------------------------------------
    Route::get('/contratos-ejecucion',              [ContratoEjecucionController::class, 'index']);
    Route::get('/contratos-ejecucion/export/excel', [ContratoEjecucionController::class, 'export']);
    Route::get('/contratos-ejecucion/{id}',         [ContratoEjecucionController::class, 'show'])->whereNumber('id');

    Route::middleware('role:admin,operador')->group(function () {
        Route::post('/contratos-ejecucion',           [ContratoEjecucionController::class, 'store']);
        Route::put('/contratos-ejecucion/{id}',       [ContratoEjecucionController::class, 'update'])->whereNumber('id');
        Route::delete('/contratos-ejecucion/{id}',    [ContratoEjecucionController::class, 'destroy'])->whereNumber('id');
    });

    // ------------------------------------------------------------------
    // Movimientos (gastos / ingresos) imputados a un contrato de ejecución
    //   GET    : todos los roles
    //   POST/PUT/DELETE : admin + operador
    //   factura: descarga del archivo adjunto (autenticado)
    // ------------------------------------------------------------------
    Route::get('/contratos-ejecucion/{id}/movimientos',
        [EjecucionMovimientoController::class, 'indexForContrato'])->whereNumber('id');
    Route::get('/movimientos/{id}',          [EjecucionMovimientoController::class, 'show'])->whereNumber('id');
    Route::get('/movimientos/{id}/factura',  [EjecucionMovimientoController::class, 'descargarFactura'])->whereNumber('id');

    Route::middleware('role:admin,operador')->group(function () {
        Route::post('/contratos-ejecucion/{id}/movimientos',
            [EjecucionMovimientoController::class, 'storeForContrato'])->whereNumber('id');
        // POST con `_method=PUT` permite enviar multipart (archivo) y ser tratado como PUT.
        Route::match(['put','post'], '/movimientos/{id}',
            [EjecucionMovimientoController::class, 'update'])->whereNumber('id');
        Route::delete('/movimientos/{id}',
            [EjecucionMovimientoController::class, 'destroy'])->whereNumber('id');
    });

    // ------------------------------------------------------------------
    // Historial de cambios (admin + operador)
    // ------------------------------------------------------------------
    Route::middleware('role:admin,operador')->group(function () {
        Route::get('/historial/{tabla}/{id}', [HistorialController::class, 'show'])
            ->whereNumber('id');
    });

    // ------------------------------------------------------------------
    // Entidades maestras: GET para todos; ABM sólo admin
    // ------------------------------------------------------------------
    foreach ([
        'tipos-contrato-principal' => TipoContratoPrincipalController::class,
        'tipos-contrato-ejecucion' => TipoContratoEjecucionController::class,
        'estados-principal'        => EstadoPrincipalController::class,
        'estados-ejecucion'        => EstadoEjecucionController::class,
        'solicitantes'             => SolicitanteController::class,
        'sectores'                 => SectorController::class,
        'utt'                      => UttController::class,
        'uvt'                      => UvtController::class,
        'personal'                 => PersonalController::class,
    ] as $slug => $controller) {
        Route::get("/{$slug}",            [$controller, 'index']);
        Route::get("/{$slug}/{id}",       [$controller, 'show']);

        Route::middleware('role:admin')->group(function () use ($slug, $controller) {
            Route::post("/{$slug}",       [$controller, 'store']);
            Route::put("/{$slug}/{id}",   [$controller, 'update']);
            Route::delete("/{$slug}/{id}",[$controller, 'destroy']);
        });
    }

    // ------------------------------------------------------------------
    // Administración de usuarios (sólo admin)
    // ------------------------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get   ('/usuarios',                     [UserRoleController::class, 'index']);
        Route::post  ('/usuarios',                     [UserRoleController::class, 'store']);
        Route::get   ('/usuarios/{username}',          [UserRoleController::class, 'show']);
        Route::put   ('/usuarios/{username}',          [UserRoleController::class, 'update']);
        Route::delete('/usuarios/{username}',          [UserRoleController::class, 'destroy']);
        Route::post  ('/usuarios/{username}/password', [UserRoleController::class, 'resetPassword']);

        // Exportar / Importar toda la base de datos (Excel)
        Route::get ('/admin/db/export', [DatabaseBackupController::class, 'export']);
        Route::post('/admin/db/import', [DatabaseBackupController::class, 'import']);
    });
});
