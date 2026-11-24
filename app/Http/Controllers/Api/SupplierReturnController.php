<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierReturn;
use App\Services\CreateSupplierReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F5 – Supplier returns.
 */
class SupplierReturnController extends Controller
{
    public function index(Request $request)
    {
        $returns = SupplierReturn::query()
            ->with('supplier:supplier_code,name')
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('supplier_code'), fn ($q) => $q->where('supplier_code', $request->supplier_code))
            ->when($request->filled('grn_number'), fn ($q) => $q->where('grn_number', $request->grn_number))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $returns]);
    }

    public function show(SupplierReturn $supplierReturn)
    {
        return response()->json(['data' => $this->detail($supplierReturn)]);
    }

    public function store(Request $request, CreateSupplierReturn $creator)
    {
        $data = $request->validate([
            'grn_number' => ['nullable', 'exists:goods_receipts,grn_number'],
            'supplier_code' => ['required_without:grn_number', 'nullable', 'exists:suppliers,supplier_code'],
            'warehouse_code' => ['required_without:grn_number', 'nullable', 'exists:warehouses,warehouse_code'],
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'distinct', 'exists:products,sku'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'reason.required' => 'Ilagay kung bakit ibinabalik.',
            'items.*.sku.distinct' => 'Dalawang beses ang parehong SKU.',
        ]);

        $return = $creator->create($data, $request->user()->employee_no);

        return response()->json(['data' => $this->detail($return)], 201);
    }

    /**
     * Puwede lang i-cancel kapag HINDI pa naipapadala sa INV:
     *   - failed (hal. 422 kulang ang stock), o not_sent
     *   - pending habang INTEGRATION_ENABLED=false (walang nagpapadala)
     * Binubura ang row sa outbox para walang maipadala o ma-retry.
     */
    public function cancel(SupplierReturn $supplierReturn)
    {
        $return = DB::transaction(function () use ($supplierReturn) {
            $return = SupplierReturn::lockForUpdate()->findOrFail($supplierReturn->id);
            $outbox = $return->stockOutbox()->lockForUpdate()->first();

            $notSentYet = in_array($return->stock_sync_status, ['not_sent', 'failed'], true)
                || ($return->stock_sync_status === 'pending' && ! config('integration.enabled') && $outbox?->status === 'pending');

            if ($return->status !== SupplierReturn::PENDING || ! $notSentYet) {
                throw ValidationException::withMessages([
                    'status' => ['Hindi na puwedeng i-cancel: naipadala na, o ipinapadala na sa INV.'],
                ]);
            }

            if ($outbox && $outbox->status !== 'sent') {
                $outbox->delete();
            }

            $return->update(['status' => SupplierReturn::CANCELLED, 'stock_sync_status' => 'not_sent']);

            return $return;
        });

        return response()->json(['data' => $this->detail($return)]);
    }

    private function detail(SupplierReturn $return): SupplierReturn
    {
        return $return->fresh()->load([
            'items.product:sku,name,unit',
            'supplier:supplier_code,name',
            'stockOutbox:id,reference_number,target_system,endpoint,status,attempts,last_error,next_attempt_at,sent_at',
        ]);
    }
}
