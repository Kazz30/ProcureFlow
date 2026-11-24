<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SupplierProduct;
use App\Support\DocumentNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A4 – PR → PO.
 *  - Default supplier: ang preferred supplier ng SKU (supplier_products.is_preferred).
 *  - Default unit_cost: galing sa supplier_products.
 *  - Default warehouse_code: ang default warehouse ng branch_code.
 *  - Kapag iba-iba ang supplier ng mga SKU, hatiin sa ilang PO.
 *  - Ang PR ay magiging Converted.
 */
class PurchaseOrderFromRequest
{
    /**
     * Ang mungkahing PO para sa screen (hindi pa naka-save).
     */
    public function draft(PurchaseRequest $pr): array
    {
        $pr->loadMissing('items.product:sku,name,unit');

        $options = SupplierProduct::query()
            ->with('supplier:supplier_code,name,status')
            ->whereIn('sku', $pr->items->pluck('sku'))
            ->get()
            ->filter(fn ($sp) => $sp->supplier?->status === 'Active')
            ->groupBy('sku');

        $warehouses = DB::table('warehouses')
            ->where('branch_code', $pr->branch_code)
            ->where('status', 'Active')
            ->orderByDesc('is_default')
            ->orderBy('warehouse_code')
            ->get(['warehouse_code', 'name', 'is_default']);

        return [
            'pr_number' => $pr->pr_number,
            'branch_code' => $pr->branch_code,
            'warehouse_code' => $warehouses->firstWhere('is_default', true)?->warehouse_code
                ?? $warehouses->first()?->warehouse_code,
            'warehouses' => $warehouses,
            'items' => $pr->items->map(function ($item) use ($options) {
                /** @var Collection $choices */
                $choices = ($options[$item->sku] ?? collect())
                    ->sortByDesc('is_preferred')
                    ->values();
                $preferred = $choices->firstWhere('is_preferred', true);

                return [
                    'purchase_request_item_id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->product?->name,
                    'unit' => $item->product?->unit,
                    'quantity' => $item->quantity,
                    'supplier_code' => $preferred?->supplier_code,
                    'unit_cost' => $preferred?->unit_cost,
                    'suppliers' => $choices->map(fn ($sp) => [
                        'supplier_code' => $sp->supplier_code,
                        'name' => $sp->supplier->name,
                        'unit_cost' => $sp->unit_cost,
                        'lead_time_days' => $sp->lead_time_days,
                        'is_preferred' => $sp->is_preferred,
                    ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * Gumagawa ng PO (isa bawat supplier) mula sa approved na PR.
     *
     * @param  array{warehouse_code?:string|null, items?: array<int, array{purchase_request_item_id:int,
     *               supplier_code?:string|null, unit_cost?:numeric|null, quantity?:int|null}>}  $input
     * @return Collection<int, PurchaseOrder>
     */
    public function convert(PurchaseRequest $pr, array $input, ?string $orderedBy): Collection
    {
        return DB::transaction(function () use ($pr, $input, $orderedBy) {
            // I-lock ang PR para hindi ito ma-convert nang dalawang beses.
            $pr = PurchaseRequest::with('items')->lockForUpdate()->findOrFail($pr->id);

            if ($pr->status !== PurchaseRequest::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => ["Approved na PR lang ang puwedeng gawing PO (ngayon: {$pr->status})."],
                ]);
            }

            $draft = $this->draft($pr);
            $overrides = collect($input['items'] ?? [])->keyBy('purchase_request_item_id');

            $warehouseCode = $input['warehouse_code'] ?? $draft['warehouse_code'];
            if (! $warehouseCode || ! $draft['warehouses']->contains('warehouse_code', $warehouseCode)) {
                throw ValidationException::withMessages([
                    'warehouse_code' => ["Walang ganitong warehouse para sa {$pr->branch_code}."],
                ]);
            }

            // Buuin ang bawat linya: default mula sa draft, palitan kung may pinili ang user.
            $lines = collect($draft['items'])->map(function ($line, $index) use ($overrides) {
                $override = $overrides[$line['purchase_request_item_id']] ?? [];
                $supplierCode = $override['supplier_code'] ?? $line['supplier_code'];

                if (! $supplierCode) {
                    throw ValidationException::withMessages([
                        "items.$index.supplier_code" => ["Walang preferred supplier ang {$line['sku']}. Pumili ng supplier."],
                    ]);
                }

                $choice = collect($line['suppliers'])->firstWhere('supplier_code', $supplierCode);
                if (! $choice) {
                    throw ValidationException::withMessages([
                        "items.$index.supplier_code" => ["Walang presyo si $supplierCode para sa {$line['sku']} sa Supplier Products."],
                    ]);
                }

                return [
                    'sku' => $line['sku'],
                    'supplier_code' => $supplierCode,
                    'quantity' => (int) ($override['quantity'] ?? $line['quantity']),
                    'unit_cost' => $override['unit_cost'] ?? $choice['unit_cost'],
                ];
            });

            // Hatiin ayon sa supplier: isang PO bawat supplier.
            $orders = $lines->groupBy('supplier_code')->map(function ($supplierLines, $supplierCode) use ($pr, $warehouseCode, $orderedBy) {
                $po = PurchaseOrder::create([
                    'po_number' => DocumentNumber::next('PO'),
                    'pr_number' => $pr->pr_number,
                    'supplier_code' => $supplierCode,
                    'branch_code' => $pr->branch_code,
                    'warehouse_code' => $warehouseCode,
                    'status' => PurchaseOrder::DRAFT,
                    'ordered_by' => $orderedBy,
                    'ordered_at' => now(),
                ]);

                foreach ($supplierLines as $line) {
                    $po->items()->create([
                        'sku' => $line['sku'],
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                        'received_quantity' => 0,
                    ]);
                }

                return $po;
            })->values();

            $pr->update(['status' => PurchaseRequest::CONVERTED]);

            return $orders;
        });
    }
}
