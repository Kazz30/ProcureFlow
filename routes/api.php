<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierProductController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API para sa sariling frontend ng PROC (/api/...)
|--------------------------------------------------------------------------
| Login gamit ang Sanctum token (Authorization: Bearer ...).
*/
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Mga dropdown (galing sa mga kopya)
    Route::get('/lookups/products', [LookupController::class, 'products']);
    Route::get('/lookups/branches', [LookupController::class, 'branches']);
    Route::get('/lookups/warehouses', [LookupController::class, 'warehouses']);

    // F6 – Suppliers at supplier products: lahat ng role ay puwedeng tumingin...
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::get('/supplier-products', [SupplierProductController::class, 'index']);

    // ...pero Admin at Purchasing Officer lang ang puwedeng mag-edit.
    Route::middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PURCHASING)->group(function () {
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::post('/supplier-products', [SupplierProductController::class, 'store']);
        Route::put('/supplier-products/{supplierProduct}', [SupplierProductController::class, 'update']);
        Route::delete('/supplier-products/{supplierProduct}', [SupplierProductController::class, 'destroy']);
    });
});

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
