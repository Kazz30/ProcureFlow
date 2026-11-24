<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoodsReceiptController;
use App\Http\Controllers\Api\SupplierIssueController;
use App\Http\Controllers\Api\SupplierReturnController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierProductController;
use App\Http\Controllers\Integration;
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

    // A4 – Purchase requests
    Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
    Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
    Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])
        ->middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PURCHASING);
    // Ang Manager ang nag-a-approve o nagre-reject.
    Route::middleware('role:'.User::ROLE_MANAGER)->group(function () {
        Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve']);
        Route::post('/purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject']);
    });

    // A4 – PR → PO at Purchase Orders
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PURCHASING)->group(function () {
        Route::get('/purchase-requests/{purchaseRequest}/po-draft', [PurchaseOrderController::class, 'draftFromRequest']);
        Route::post('/purchase-requests/{purchaseRequest}/convert', [PurchaseOrderController::class, 'storeFromRequest']);
        Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'markSent']);
    });
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
        ->middleware('role:'.User::ROLE_MANAGER);
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PURCHASING.','.User::ROLE_MANAGER);

    // F5 – Goods receipts at supplier returns
    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index']);
    Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
    Route::get('/supplier-returns', [SupplierReturnController::class, 'index']);
    Route::get('/supplier-returns/{supplierReturn}', [SupplierReturnController::class, 'show']);
    Route::middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PURCHASING)->group(function () {
        Route::post('/purchase-orders/{purchaseOrder}/receive', [GoodsReceiptController::class, 'store']);
        Route::post('/supplier-returns', [SupplierReturnController::class, 'store']);
        Route::post('/supplier-returns/{supplierReturn}/cancel', [SupplierReturnController::class, 'cancel']);
    });

    // F7 – Supplier issues (galing CRM)
    Route::get('/supplier-issues', [SupplierIssueController::class, 'index']);
    Route::get('/supplier-issues/{supplierIssue}', [SupplierIssueController::class, 'show']);
    Route::put('/supplier-issues/{supplierIssue}', [SupplierIssueController::class, 'update'])
        ->middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PURCHASING.','.User::ROLE_MANAGER);
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
        // F6 – para sa INV at CRM
        Route::get('/suppliers', [Integration\SupplierController::class, 'index']);
        Route::get('/supplier-products', [Integration\SupplierProductController::class, 'index']);
        // F4 – galing INV
        Route::post('/purchase-requests', [Integration\PurchaseRequestController::class, 'store']);
        // F7 – galing CRM
        Route::post('/supplier-issues', [Integration\SupplierIssueController::class, 'store']);
    });
});
