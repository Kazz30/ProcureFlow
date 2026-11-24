<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierIssue;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * F7 – Supplier issues (galing CRM). Listahan at pag-update ng status/resolution.
 */
class SupplierIssueController extends Controller
{
    public function index(Request $request)
    {
        $issues = SupplierIssue::query()
            ->with(['supplier:supplier_code,name', 'product:sku,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('supplier_code'), function ($q) use ($request) {
                $request->supplier_code === 'none'
                    ? $q->whereNull('supplier_code')
                    : $q->where('supplier_code', $request->supplier_code);
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $issues]);
    }

    public function show(SupplierIssue $supplierIssue)
    {
        return response()->json(['data' => $supplierIssue->load(['supplier:supplier_code,name,contact_person,email,phone', 'product:sku,name,unit'])]);
    }

    public function update(Request $request, SupplierIssue $supplierIssue)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SupplierIssue::STATUSES)],
            'resolution' => [
                Rule::requiredIf(in_array($request->status, [SupplierIssue::RESOLVED, SupplierIssue::CLOSED], true)),
                'nullable', 'string', 'max:2000',
            ],
            // Puwedeng itakda ang supplier kapag walang preferred noong dumating ang reklamo.
            'supplier_code' => ['nullable', 'exists:suppliers,supplier_code'],
        ], ['resolution.required' => 'Isulat ang resolution bago gawing Resolved o Closed.']);

        $supplierIssue->update(array_filter($data, fn ($v, $k) => $k !== 'supplier_code' || $v !== null, ARRAY_FILTER_USE_BOTH));

        return $this->show($supplierIssue->fresh());
    }
}
