<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Mga listahan para sa dropdown ng mga screen (galing sa mga KOPYA, read-only).
 */
class LookupController extends Controller
{
    public function products()
    {
        return response()->json([
            'data' => Product::where('status', 'Active')->orderBy('sku')
                ->get(['sku', 'name', 'unit', 'category', 'cost', 'reorder_level']),
        ]);
    }

    public function branches()
    {
        return response()->json([
            'data' => DB::table('branches')->where('status', 'Active')->orderBy('branch_code')
                ->get(['branch_code', 'name', 'city']),
        ]);
    }

    public function warehouses()
    {
        return response()->json([
            'data' => DB::table('warehouses')->where('status', 'Active')->orderBy('warehouse_code')
                ->get(['warehouse_code', 'name', 'branch_code', 'is_default']),
        ]);
    }
}
