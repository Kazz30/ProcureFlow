<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F5 – Pag-receive ng PO (GRN).
 * Sa iisang transaction:
 *   - gagawin ang GRN at mga item
 *   - dadagdagan ang received_quantity ng PO items
 *   - magiging Partially Received o Received ang PO
 *   - (Hakbang 10) ilalagay sa outbox ang PURCHASE_IN papunta sa INV
 */
class ReceiveGoods
{
    /**
     * @param  array<int, array{purchase_order_item_id:int, quantity:int}>  $lines
     */
    public function receive(PurchaseOrder $po, array $lines, ?string $receivedBy): GoodsReceipt
    {
        return DB::transaction(function () use ($po, $lines, $receivedBy) {
            // I-lock ang PO para walang sabay na pag-receive na lalampas sa order.
            $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($po->id);

            if (! in_array($po->status, PurchaseOrder::RECEIVABLE, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Hindi puwedeng i-receive ang PO na {$po->status}. Dapat Approved, Sent o Partially Received."],
                ]);
            }

            $lines = collect($lines)->filter(fn ($l) => (int) $l['quantity'] > 0)->values();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Maglagay ng kahit isang item na may dumating.']]);
            }

            $grn = GoodsReceipt::create([
                'grn_number' => DocumentNumber::next('GRN'),
                'po_number' => $po->po_number,
                'warehouse_code' => $po->warehouse_code,
                'branch_code' => $po->branch_code,
                'received_by' => $receivedBy,
                'received_at' => now(),
                'stock_sync_status' => 'not_sent',
            ]);

            foreach ($lines as $index => $line) {
                $poItem = $po->items->firstWhere('id', (int) $line['purchase_order_item_id']);
                if (! $poItem) {
                    throw ValidationException::withMessages(["items.$index" => ['Wala ang item na ito sa PO.']]);
                }

                $remaining = $poItem->quantity - $poItem->received_quantity;
                if ((int) $line['quantity'] > $remaining) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => ["{$poItem->sku}: $remaining na lang ang hindi pa na-receive."],
                    ]);
                }

                $grn->items()->create(['sku' => $poItem->sku, 'quantity' => (int) $line['quantity']]);
                $poItem->increment('received_quantity', (int) $line['quantity']);
            }

            $po->refresh()->load('items');
            $allReceived = $po->items->every(fn ($i) => $i->received_quantity >= $i->quantity);
            $po->update(['status' => $allReceived ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIALLY_RECEIVED]);

            return $grn->load('items');
        });
    }
}
