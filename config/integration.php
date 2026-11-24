<?php

/*
|--------------------------------------------------------------------------
| Integration settings (Bahagi B6 at B7)
|--------------------------------------------------------------------------
|
| Dito binabasa ang lahat ng integration variable mula sa .env.
| Halimbawa ng paggamit:
|   config('integration.system_code')          // "PROC"
|   config('integration.enabled')              // false sa Yugto 1
|   config('integration.api_key')              // sariling key (para sa X-API-KEY na papasok)
|   config('integration.systems.INV.url')      // base URL ng INV, may /api/integration/v1 na
|   config('integration.systems.INV.key')      // key na ipapadala natin sa INV
|
*/

return [

    // Ang code ng system na ito: CRM, PROC, INV, o HR.
    'system_code' => env('SYSTEM_CODE', 'PROC'),

    // false = walang ipapadala sa ibang system; naiiwan lang sa outbox (pending).
    'enabled' => (bool) env('INTEGRATION_ENABLED', false),

    // Ang sariling key. Ito ang hinahanap sa X-API-KEY header ng mga papasok na request.
    'api_key' => env('INTEGRATION_API_KEY'),

    // Ang ibang system: base URL at ang key na ibinigay nila sa atin (pribadong mensahe).
    'systems' => [
        'CRM' => [
            'url' => env('CRM_API_URL'),
            'key' => env('CRM_API_KEY'),
        ],
        'PROC' => [
            'url' => env('PROC_API_URL'),
            'key' => env('PROC_API_KEY'),
        ],
        'INV' => [
            'url' => env('INV_API_URL'),
            'key' => env('INV_API_KEY'),
        ],
        'HR' => [
            'url' => env('HR_API_URL'),
            'key' => env('HR_API_KEY'),
        ],
    ],

    // Timeout ng bawat tawag sa ibang system (segundo).
    'timeout' => 10,

];
