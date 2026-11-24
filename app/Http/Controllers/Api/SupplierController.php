<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * F6 – Suppliers (para sa screen ng PROC, hindi integration endpoint).
 */
class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::query()
            ->withCount('products')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->search.'%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)
                    ->orWhere('supplier_code', 'like', $s)
                    ->orWhere('contact_person', 'like', $s));
            })
            ->orderBy('supplier_code')
            ->get();

        return response()->json(['data' => $suppliers]);
    }

    public function show(Supplier $supplier)
    {
        return response()->json(['data' => $supplier->load('products')]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $supplier = DB::transaction(function () use ($data) {
            // Ginagawa ang code sa loob ng transaction para walang doble.
            return Supplier::create($data + ['supplier_code' => Supplier::nextCode()]);
        });

        return response()->json(['data' => $supplier], 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        // Hindi binabago ang supplier_code (B4: ang code ang ipinapasa sa ibang system).
        $supplier->update($this->validated($request));

        return response()->json(['data' => $supplier]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
