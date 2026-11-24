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

// Yugto 2: integration:sync-masterdata bawat 15 minuto (idadagdag kapag nagawa na ang command).
