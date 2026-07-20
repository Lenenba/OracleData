<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nettoie chaque jour les fichiers d'export serveur expirés (rétention bornée).
Schedule::command('exports:purge')->dailyAt('03:00')->withoutOverlapping();

// Dispatch chaque minute les requêtes planifiées dont l'échéance est atteinte.
Schedule::command('schedules:run-due')->everyMinute()->withoutOverlapping();
