<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstadoController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\SolicitanteController;
use App\Http\Controllers\TipoContratoController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UttController;
use App\Http\Controllers\UvtController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - ATLAS
|--------------------------------------------------------------------------
| Prefijo: /api  (configurado en bootstrap/app.php)
| Roles:
|   admin    : acceso total
|   operador : GET + POST + PUT en contratos; GET en entidades maestras
|   consulta : sólo GET
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
    // Dashboard (cualquier rol autenticado)
    // ------------------------------------------------------------------
    Route::get('/dashboard/kpis',         [DashboardController::class, 'kpis']);
    Route::get('/dashboard/vencimientos', [DashboardController::class, 'vencimientos']);

    // ------------------------------------------------------------------
    // Contratos
    //   GET  : todos los roles
    //   POST/PUT : admin + operador
    //   DELETE   : admin
    //   export   : todos los roles (requiere auth)
    // ------------------------------------------------------------------
    Route::get('/contratos',              [ContratoController::class, 'index']);
    Route::get('/contratos/export/excel', [ContratoController::class, 'export']);
    Route::get('/contratos/{id}',         [ContratoController::class, 'show'])->whereNumber('id');

    Route::middleware('role:admin,operador')->group(function () {
        Route::post('/contratos',         [ContratoController::class, 'store']);
        Route::put('/contratos/{id}',     [ContratoController::class, 'update'])->whereNumber('id');
    });

    Route::middleware('role:admin')->delete('/contratos/{id}', [ContratoController::class, 'destroy'])->whereNumber('id');

    // ------------------------------------------------------------------
    // Entidades maestras: GET para todos; ABM sólo admin
    // ------------------------------------------------------------------
    foreach ([
        'estados'         => EstadoController::class,
        'tipos-contrato'  => TipoContratoController::class,
        'solicitantes'    => SolicitanteController::class,
        'sectores'        => SectorController::class,
        'utt'             => UttController::class,
        'uvt'             => UvtController::class,
        'personal'        => PersonalController::class,
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
    // Gestión de roles (sólo admin)
    // ------------------------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get('/usuarios',              [UserRoleController::class, 'index']);
        Route::put('/usuarios/{username}',   [UserRoleController::class, 'update']);
    });
});
