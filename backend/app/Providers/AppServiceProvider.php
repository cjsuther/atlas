<?php

namespace App\Providers;

use App\Console\Commands\EnsureAdminCommand;
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

        // Sanctum: usar el modelo UserRole como provider de autenticación
        Sanctum::usePersonalAccessTokenModel(\Laravel\Sanctum\PersonalAccessToken::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnsureAdminCommand::class,
            ]);
        }
    }
}
