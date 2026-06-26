<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Drenado de la cola (correos en segundo plano) — opción para hosting gratuito
|--------------------------------------------------------------------------
| Muchos hosting gratuitos/baratos ofrecen cron pero no procesos daemon, así
| que en vez de mantener vivo `php artisan queue:work`, el scheduler procesa
| la cola hasta vaciarla cada minuto. En el servidor basta UNA línea de cron:
|
|     * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
|
| En local equivale a dejar corriendo `php artisan schedule:work`.
| (Si prefieres un worker dedicado, usa `php artisan queue:work` y omite esto.)
*/
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();
