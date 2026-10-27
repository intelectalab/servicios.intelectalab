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

// Cada madrugada, las cotizaciones enviadas que pasaron su fecha de validez quedan Vencidas (RN-COM-05).
// Hora de Lima: a las 00:10 (05:10 UTC) la fecha UTC ya coincide con la de Perú.
Illuminate\Support\Facades\Schedule::command('quotations:expire')->dailyAt('00:10')->timezone('America/Lima');
