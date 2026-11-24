<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * F6 – Supplier products: price list at preferred supplier ng bawat SKU.
 */
class SupplierProductController extends Controller
{
    public function index(Request $request)
    {
        $items = SupplierProduct::query()
            ->with(['supplier:supplier_code,name', 'product:sku,name,unit'])
            ->when($request->filled('supplier_code'), fn ($q) => $q->where('supplier_code', $request->supplier_code))
            ->when($request->filled('sku'), fn ($q) => $q->where('sku', $request->sku))
            ->orderBy('sku')
            ->orderByDesc('is_preferred')
            ->orderBy('supplier_code')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'exists:suppliers,supplier_code'],
            'sku' => [
                'required', 'exists:products,sku',
                Rule::unique('supplier_products')->where('supplier_code', $request->supplier_code),
            ],
            ...$this->priceRules(),
        ], [
            'sku.unique' => 'May presyo na ang supplier na ito para sa SKU na ito.',
        ]);

        $item = new SupplierProduct($data);
        $item->saveKeepingOnePreferred();

        return response()->json(['data' => $item->load(['supplier:supplier_code,name', 'product:sku,name,unit'])], 201);
    }

    public function update(Request $request, SupplierProduct $supplierProduct)
    {
        // supplier_code at sku ay hindi binabago; gumawa ng bago kung iba.
        $supplierProduct->fill($request->validate($this->priceRules()));
        $supplierProduct->saveKeepingOnePreferred();

        return response()->json(['data' => $supplierProduct->load(['supplier:supplier_code,name', 'product:sku,name,unit'])]);
    }

    public function destroy(SupplierProduct $supplierProduct)
    {
        $supplierProduct->delete();

        return response()->json(['message' => 'Natanggal na.']);
    }

    private function priceRules(): array
    {
        return [
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_preferred' => ['required', 'boolean'],
        ];
    }
}
