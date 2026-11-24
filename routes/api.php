<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Integration API (B5, B7) – /api/integration/v1/...
|--------------------------------------------------------------------------
| /health: walang API key.
| Lahat ng iba: may X-API-KEY (middleware alias: integration.key).
*/
Route::prefix('integration/v1')->group(function () {

    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'system' => config('integration.system_code'),
            'version' => 'v1',
            'time' => now()->toIso8601String(),
        ]);
    });

    Route::middleware('integration.key')->group(function () {
        // Hakbang 8: /suppliers, /supplier-products, /purchase-requests, /supplier-issues
    });
});
