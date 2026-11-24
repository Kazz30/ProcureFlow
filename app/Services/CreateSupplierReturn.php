<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\SupplierReturn;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F5 – Supplier return.
 * Kapag galing sa GRN: ang supplier, warehouse at branch ay galing sa GRN/PO,
 * at hindi puwedeng magbalik nang higit sa na-receive (bawas ang naibalik na).
 * (Hakbang 10) ilalagay sa outbox ang SUPPLIER_RETURN_OUT papunta sa INV.
 */
class CreateSupplierReturn
{
    /**
     * @param  array{grn_number?:string|null, supplier_code?:string|null, warehouse_code?:string|null,
     *               reason?:string|null, items: array<int, array{sku:string, quantity:int}>}  $data
     */
    public function create(array $data, ?string $returnedBy): SupplierReturn
    {
        return DB::transaction(function () use ($data, $returnedBy) {
            if (! empty($data['grn_number'])) {
                $grn = GoodsReceipt::with(['items', 'purchaseOrder'])
                    ->where('grn_number', $data['grn_number'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $supplierCode = $grn->purchaseOrder->supplier_code;
                $warehouseCode = $grn->warehouse_code;
                $branchCode = $grn->branch_code;

                $this->checkAgainstGrn($grn, $data['items']);
            } else {
                $supplierCode = $data['supplier_code'];
                $warehouseCode = $data['warehouse_code'];
                $branchCode = DB::table('warehouses')->where('warehouse_code', $warehouseCode)->value('branch_code');
            }

            $return = SupplierReturn::create([
                'return_number' => DocumentNumber::next('SR'),
                'supplier_code' => $supplierCode,
                'grn_number' => $data['grn_number'] ?? null,
                'warehouse_code' => $warehouseCode,
                'branch_code' => $branchCode,
                'reason' => $data['reason'] ?? null,
                'returned_by' => $returnedBy,
                'status' => SupplierReturn::PENDING,
                'stock_sync_status' => 'not_sent',
            ]);

            foreach ($data['items'] as $item) {
                $return->items()->create(['sku' => $item['sku'], 'quantity' => (int) $item['quantity']]);
            }

            return $return->load('items');
        });
    }

    /** Hindi puwedeng magbalik nang higit sa na-receive sa GRN, bawas ang naibalik na. */
    private function checkAgainstGrn(GoodsReceipt $grn, array $items): void
    {
        $alreadyReturned = DB::table('supplier_return_items')
            ->join('supplier_returns', 'supplier_returns.id', '=', 'supplier_return_items.supplier_return_id')
            ->where('supplier_returns.grn_number', $grn->grn_number)
            ->where('supplier_returns.status', '!=', SupplierReturn::CANCELLED)
            ->groupBy('supplier_return_items.sku')
            ->selectRaw('supplier_return_items.sku as sku, SUM(supplier_return_items.quantity) as returned')
            ->pluck('returned', 'sku');

        foreach ($items as $index => $item) {
            $received = (int) $grn->items->where('sku', $item['sku'])->sum('quantity');
            if ($received === 0) {
                throw ValidationException::withMessages([
                    "items.$index.sku" => ["Wala ang {$item['sku']} sa {$grn->grn_number}."],
                ]);
            }

            $left = $received - (int) ($alreadyReturned[$item['sku']] ?? 0);
            if ((int) $item['quantity'] > $left) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => ["{$item['sku']}: $left na lang ang puwedeng ibalik mula sa {$grn->grn_number}."],
                ]);
            }
        }
    }
}
