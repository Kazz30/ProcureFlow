<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\PurchaseOrderFromRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A4 – PR → PO at ang Purchase Orders screen.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderFromRequest $fromRequest) {}

    public function index(Request $request)
    {
        $orders = PurchaseOrder::query()
            ->with('supplier:supplier_code,name')
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->withSum('items as total_received', 'received_quantity')
            ->selectSub(
                DB::table('purchase_order_items')
                    ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0)')
                    ->whereColumn('purchase_order_id', 'purchase_orders.id'),
                'total_amount'
            )
            ->addSelect('purchase_orders.*')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('supplier_code'), fn ($q) => $q->where('supplier_code', $request->supplier_code))
            ->when($request->filled('pr_number'), fn ($q) => $q->where('pr_number', $request->pr_number))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json(['data' => $this->detail($purchaseOrder)]);
    }

    /** Mungkahing PO mula sa PR (hindi pa naka-save). */
    public function draftFromRequest(PurchaseRequest $purchaseRequest)
    {
        return response()->json(['data' => $this->fromRequest->draft($purchaseRequest)]);
    }

    /** Gumawa ng PO mula sa approved na PR. Isang PO bawat supplier. */
    public function storeFromRequest(Request $request, PurchaseRequest $purchaseRequest)
    {
        $input = $request->validate([
            'warehouse_code' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.purchase_request_item_id' => ['required', 'integer'],
            'items.*.supplier_code' => ['nullable', 'string'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $orders = $this->fromRequest->convert($purchaseRequest, $input, $request->user()->employee_no);

        return response()->json([
            'message' => count($orders) === 1 ? 'Nagawa ang 1 PO.' : 'Nagawa ang '.count($orders).' PO (isa bawat supplier).',
            'data' => $orders->map(fn ($po) => $this->detail($po)),
        ], 201);
    }

    /** Manager: Draft → Approved. */
    public function approve(PurchaseOrder $purchaseOrder)
    {
        return $this->move($purchaseOrder, [PurchaseOrder::DRAFT], PurchaseOrder::APPROVED);
    }

    /** Purchasing/Admin: Approved → Sent (naipadala na sa supplier). */
    public function markSent(PurchaseOrder $purchaseOrder)
    {
        return $this->move($purchaseOrder, [PurchaseOrder::APPROVED], PurchaseOrder::SENT);
    }

    /** Purchasing/Admin/Manager: i-cancel habang wala pang na-receive. */
    public function cancel(PurchaseOrder $purchaseOrder)
    {
        return $this->move($purchaseOrder, [PurchaseOrder::DRAFT, PurchaseOrder::APPROVED, PurchaseOrder::SENT], PurchaseOrder::CANCELLED);
    }

    private function move(PurchaseOrder $po, array $from, string $to)
    {
        $po = DB::transaction(function () use ($po, $from, $to) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);

            if (! in_array($po->status, $from, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Hindi puwedeng gawing $to ang PO na {$po->status}."],
                ]);
            }

            if ($to === PurchaseOrder::CANCELLED && $po->items()->where('received_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['May na-receive na sa PO na ito, kaya hindi na puwedeng i-cancel.'],
                ]);
            }

            $po->update(['status' => $to]);

            return $po;
        });

        return response()->json(['data' => $this->detail($po)]);
    }

    private function detail(PurchaseOrder $po): PurchaseOrder
    {
        return $po->fresh()->load([
            'supplier:supplier_code,name,contact_person,email,phone',
            'items.product:sku,name,unit',
        ]);
    }
}
