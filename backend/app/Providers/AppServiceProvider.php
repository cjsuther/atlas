<?php

namespace App\Providers;

use App\Console\Commands\EnsureAdminCommand;
use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
use App\Models\EjecucionMovimiento;
use App\Observers\ContratoHistorialObserver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Sanctum
        Sanctum::usePersonalAccessTokenModel(\Laravel\Sanctum\PersonalAccessToken::class);

        // Auditoría obligatoria (creación, edición, baja lógica).
        ContratoPrincipal::observe(ContratoHistorialObserver::class);
        ContratoEjecucion::observe(ContratoHistorialObserver::class);
        EjecucionMovimiento::observe(ContratoHistorialObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnsureAdminCommand::class,
            ]);
        }
    }
}
