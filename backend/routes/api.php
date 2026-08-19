<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContratoEjecucionController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\EjecucionMovimientoController;
use App\Http\Controllers\EstadoEjecucionController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\SolicitanteController;
use App\Http\Controllers\TipoContratoEjecucionController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UttController;
use App\Http\Controllers\UvtController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - ATLAS
|--------------------------------------------------------------------------
| Prefijo: /api  (configurado en bootstrap/app.php)
|
| La estructura organizativa es la tabla `sector`: los sectores sin dependencia
| son las Gerencias de Área.
|
|   Gerencia de Área -> Subsector -> Contrato -> Movimiento
|
| Roles:
|   admin_sistema     : todas las Gerencias de Área y sus contratos; ABM de
|                       usuarios de cualquier rol y Gerencia de Área.
|   admin_gerencia    : contratos de su Gerencia de Área; ABM de operadores.
|   operador_gerencia : contratos de su Gerencia de Área.
|
| El recorte lo aplica AccessScopeService dentro de cada servicio: las rutas
| sólo distinguen quién puede ejecutar cada acción.
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
    Route::post('/auth/logout',       [AuthController::class, 'logout']);
    Route::get ('/auth/me',           [AuthController::class, 'me']);
    Route::put ('/auth/preferencias', [AuthController::class, 'preferencias']);

    // ------------------------------------------------------------------
    // Export consolidado de todas las tablas (todos los roles autenticados).
    // ------------------------------------------------------------------
    Route::get('/export/excel', [ExportController::class, 'full']);

    // ------------------------------------------------------------------
    // Panel de Control (todos los roles autenticados, recortado por alcance)
    // ------------------------------------------------------------------
    Route::prefix('panel')->group(function () {
        Route::get('/indicadores',  [PanelController::class, 'indicadores']);
        Route::get('/calculados',   [PanelController::class, 'calculados']);
        Route::get('/saldos',       [PanelController::class, 'saldos']);
        Route::get('/por-uvt',      [PanelController::class, 'porUvt']);
        Route::get('/por-gerencia', [PanelController::class, 'porGerencia']);
        Route::get('/por-accion',   [PanelController::class, 'porAccion']);
        Route::get('/vencimientos', [PanelController::class, 'vencimientos']);
        Route::get('/rankings',     [PanelController::class, 'rankings']);
    });

    // ------------------------------------------------------------------
    // Contratos
    //   GET    : todos los roles (sólo los de su alcance)
    //   POST/PUT/DELETE : todos los roles, sobre su propia gerencia
    //   transferir      : sólo admin_sistema (puede cruzar Gerencias de Área)
    // ------------------------------------------------------------------
    Route::get('/contratos-ejecucion',              [ContratoEjecucionController::class, 'index']);
    Route::get('/contratos-ejecucion/export/excel', [ContratoEjecucionController::class, 'export']);
    Route::get('/contratos-ejecucion/{id}',         [ContratoEjecucionController::class, 'show'])->whereNumber('id');

    Route::post  ('/contratos-ejecucion',        [ContratoEjecucionController::class, 'store']);
    Route::put   ('/contratos-ejecucion/{id}',   [ContratoEjecucionController::class, 'update'])->whereNumber('id');
    Route::delete('/contratos-ejecucion/{id}',   [ContratoEjecucionController::class, 'destroy'])->whereNumber('id');

    Route::middleware('role:admin_sistema')->group(function () {
        Route::post('/contratos-ejecucion/{id}/transferir',
            [ContratoEjecucionController::class, 'transferir'])->whereNumber('id');
    });

    // ------------------------------------------------------------------
    // Movimientos de ejecución imputados a un contrato.
    // Además de facturas hay transferencias entre contratos, incentivos y MCH.
    // ------------------------------------------------------------------
    Route::get('/contratos-ejecucion/{id}/movimientos',
        [EjecucionMovimientoController::class, 'indexForContrato'])->whereNumber('id');
    Route::get('/movimientos/{id}',          [EjecucionMovimientoController::class, 'show'])->whereNumber('id');
    Route::get('/movimientos/{id}/factura',  [EjecucionMovimientoController::class, 'descargarFactura'])->whereNumber('id');

    Route::post('/contratos-ejecucion/{id}/movimientos',
        [EjecucionMovimientoController::class, 'storeForContrato'])->whereNumber('id');
    // POST con `_method=PUT` permite enviar multipart (archivo) y ser tratado como PUT.
    Route::match(['put','post'], '/movimientos/{id}',
        [EjecucionMovimientoController::class, 'update'])->whereNumber('id');
    Route::delete('/movimientos/{id}',
        [EjecucionMovimientoController::class, 'destroy'])->whereNumber('id');

    // ------------------------------------------------------------------
    // Historial de cambios
    // ------------------------------------------------------------------
    Route::get('/historial/{tabla}/{id}', [HistorialController::class, 'show'])->whereNumber('id');

    // ------------------------------------------------------------------
    // Estructura organizativa (`sectores`) y entidades maestras.
    //   GET : todos (recortado al alcance del usuario)
    //   ABM : sólo admin_sistema
    // ------------------------------------------------------------------
    foreach ([
        'tipos-contrato-ejecucion' => TipoContratoEjecucionController::class,
        'estados-ejecucion'        => EstadoEjecucionController::class,
        'solicitantes'             => SolicitanteController::class,
        'sectores'                 => SectorController::class,
        'utt'                      => UttController::class,
        'uvt'                      => UvtController::class,
        'personal'                 => PersonalController::class,
    ] as $slug => $controller) {
        Route::get("/{$slug}",            [$controller, 'index']);
        Route::get("/{$slug}/{id}",       [$controller, 'show']);

        Route::middleware('role:admin_sistema')->group(function () use ($slug, $controller) {
            Route::post("/{$slug}",       [$controller, 'store']);
            Route::put("/{$slug}/{id}",   [$controller, 'update']);
            Route::delete("/{$slug}/{id}",[$controller, 'destroy']);
        });
    }

    // ------------------------------------------------------------------
    // Administración de usuarios
    //   admin_sistema  : usuarios de cualquier rol y gerencia
    //   admin_gerencia : operadores de su propia gerencia
    // ------------------------------------------------------------------
    Route::middleware('role:admin_sistema,admin_gerencia')->group(function () {
        Route::get   ('/usuarios',                     [UserRoleController::class, 'index']);
        Route::post  ('/usuarios',                     [UserRoleController::class, 'store']);
        Route::get   ('/usuarios/{username}',          [UserRoleController::class, 'show']);
        Route::put   ('/usuarios/{username}',          [UserRoleController::class, 'update']);
        Route::delete('/usuarios/{username}',          [UserRoleController::class, 'destroy']);
        Route::post  ('/usuarios/{username}/password', [UserRoleController::class, 'resetPassword']);
    });

    // ------------------------------------------------------------------
    // Exportar / Importar toda la base de datos (Excel) — sólo admin_sistema
    // ------------------------------------------------------------------
    Route::middleware('role:admin_sistema')->group(function () {
        Route::get ('/admin/db/export', [DatabaseBackupController::class, 'export']);
        Route::post('/admin/db/import', [DatabaseBackupController::class, 'import']);
    });
});
