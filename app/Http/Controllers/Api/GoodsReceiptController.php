<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\ReceiveGoods;
use Illuminate\Http\Request;

/**
 * F5 – Goods receipts (pag-receive ng PO).
 */
class GoodsReceiptController extends Controller
{
    public function index(Request $request)
    {
        $receipts = GoodsReceipt::query()
            ->with('purchaseOrder:po_number,supplier_code', 'purchaseOrder.supplier:supplier_code,name')
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->when($request->filled('po_number'), fn ($q) => $q->where('po_number', $request->po_number))
            ->when($request->filled('stock_sync_status'), fn ($q) => $q->where('stock_sync_status', $request->stock_sync_status))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $receipts]);
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        return response()->json(['data' => $this->detail($goodsReceipt)]);
    }

    /** POST /api/purchase-orders/{po}/receive */
    public function store(Request $request, PurchaseOrder $purchaseOrder, ReceiveGoods $receiveGoods)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        $grn = $receiveGoods->receive($purchaseOrder, $data['items'], $request->user()->employee_no);

        return response()->json(['data' => $this->detail($grn)], 201);
    }

    private function detail(GoodsReceipt $grn): GoodsReceipt
    {
        return $grn->fresh()->load([
            'items.product:sku,name,unit',
            'purchaseOrder:id,po_number,pr_number,supplier_code,status',
            'purchaseOrder.supplier:supplier_code,name',
        ]);
    }
}
