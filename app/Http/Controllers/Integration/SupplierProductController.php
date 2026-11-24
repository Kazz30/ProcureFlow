<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\SupplierProduct;
use App\Support\IntegrationList;
use Illuminate\Http\Request;

/**
 * F6 – GET /api/integration/v1/supplier-products (para sa INV at CRM).
 */
class SupplierProductController extends Controller
{
    public function index(Request $request)
    {
        $query = SupplierProduct::query()->orderBy('sku');

        return IntegrationList::respond($request, $query, fn (SupplierProduct $sp) => [
            'supplier_code' => $sp->supplier_code,
            'sku' => $sp->sku,
            'unit_cost' => number_format((float) $sp->unit_cost, 2, '.', ''),  // B5: 2 decimals
            'lead_time_days' => (int) $sp->lead_time_days,
            'is_preferred' => (bool) $sp->is_preferred,
            'updated_at' => IntegrationList::iso($sp->updated_at),
        ], 'supplier_code');
    }
}
