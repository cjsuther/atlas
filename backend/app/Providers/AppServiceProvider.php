<?php

namespace App\Providers;

use App\Console\Commands\EnsureAdminCommand;
use App\Console\Commands\ImportarLegacyCommand;
use App\Console\Commands\LimpiarCommand;
use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
use App\Models\EjecucionMovimiento;
use App\Observers\ContratoHistorialObserver;
use App\Support\SectorTree;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // La jerarquía de sectores se consulta muchas veces por request
        // (alcance del usuario, agrupaciones del panel): se resuelve una vez.
        $this->app->singleton(SectorTree::class);
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $this->limitarIntentosDeLogin();

        // Sanctum
        Sanctum::usePersonalAccessTokenModel(\Laravel\Sanctum\PersonalAccessToken::class);

        // Auditoría obligatoria (creación, edición, baja lógica).
        ContratoPrincipal::observe(ContratoHistorialObserver::class);
        ContratoEjecucion::observe(ContratoHistorialObserver::class);
        EjecucionMovimiento::observe(ContratoHistorialObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnsureAdminCommand::class,
                ImportarLegacyCommand::class,
                LimpiarCommand::class,
            ]);
        }
    }

    /**
     * Límite de intentos sobre el login.
     *
     * Sin esto el endpoint admite fuerza bruta y password spraying, cada intento
     * dispara un bind contra el AD —con riesgo de bloquear cuentas del
     * directorio— y cada credencial válida desconocida da de alta una fila en
     * `user_roles`. Se acota por usuario y por origen.
     */
    private function limitarIntentosDeLogin(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $usuario = mb_strtolower((string) $request->input('username'));

            return [
                Limit::perMinute(5)->by($usuario . '|' . $request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
