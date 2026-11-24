<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (B7) – pinapatakbo ng "scheduler" service sa Docker:
|   php artisan schedule:work
|--------------------------------------------------------------------------
*/

// Ipadala ang mga pending sa integration_outbox bawat minuto.
// withoutOverlapping: hindi sasabay ang bagong takbo kung hindi pa tapos ang nauna.
Schedule::command('integration:process-outbox')
    ->everyMinute()
    ->withoutOverlapping();

// Kunin ang mga kopya (HR: employees/branches/departments, INV: products/warehouses) bawat 15 minuto.
// Walang ginagawa habang INTEGRATION_ENABLED=false.
Schedule::command('integration:sync-masterdata')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
