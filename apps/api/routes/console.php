<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpia de la base las sesiones (tokens) que ya vencieron hace más de un día.
Illuminate\Support\Facades\Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Cada madrugada, los ciclos vencidos de los servicios recurrentes dejan su evento facturable.
Illuminate\Support\Facades\Schedule::command('recurring:run-cycles')->dailyAt('05:00');
