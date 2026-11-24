<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\IntegrationList;
use Illuminate\Http\Request;

/**
 * F6 – GET /api/integration/v1/suppliers (para sa INV at CRM).
 */
class SupplierController extends Controller
{
    public function index(Request $request)
    {
        return IntegrationList::respond($request, Supplier::query(), fn (Supplier $s) => [
            'supplier_code' => $s->supplier_code,
            'name' => $s->name,
            'contact_person' => $s->contact_person,
            'email' => $s->email,
            'phone' => $s->phone,
            'status' => $s->status,
            'updated_at' => IntegrationList::iso($s->updated_at),
        ], 'supplier_code');
    }
}
