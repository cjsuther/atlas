<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Los tokens vencidos dejan de servir por configuración, pero la fila queda:
// se limpian una vez por día.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
