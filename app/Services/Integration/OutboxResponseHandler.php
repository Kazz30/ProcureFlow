<?php

namespace App\Services\Integration;

use App\Models\GoodsReceipt;
use App\Models\IntegrationOutbox;
use App\Models\SupplierReturn;

/**
 * A4 – "Pag-save ng sagot ng ibang system".
 * Tinatawag ng ProcessOutbox pagkatapos ng bawat padala, para i-update ang
 * sariling record gamit ang reference_number.
 *
 *   POST INV /stock-movements, ref GRN-... → goods_receipts.stock_sync_status + movement_number
 *   POST INV /stock-movements, ref SR-...  → supplier_returns (+ status Returned kapag synced)
 */
class OutboxResponseHandler
{
    /** 2xx ang sagot. */
    public function sent(IntegrationOutbox $row, mixed $body): void
    {
        if ($row->target_system === 'INV' && $row->endpoint === '/stock-movements') {
            $movementNumber = is_array($body) ? data_get($body, 'data.movement_number') : null;

            $this->stockDocument($row->reference_number)?->update(array_filter([
                'stock_sync_status' => 'synced',
                'movement_number' => $movementNumber,
            ]) + $this->returnedStatus($row->reference_number));
        }
    }

    /** Failed na (422, o naubos ang 5 attempts). */
    public function failed(IntegrationOutbox $row): void
    {
        if ($row->target_system === 'INV' && $row->endpoint === '/stock-movements') {
            $this->stockDocument($row->reference_number)?->update(['stock_sync_status' => 'failed']);
        }
    }

    /** Nasa outbox ulit (Retry button). */
    public function retried(IntegrationOutbox $row): void
    {
        if ($row->target_system === 'INV' && $row->endpoint === '/stock-movements') {
            $this->stockDocument($row->reference_number)?->update(['stock_sync_status' => 'pending']);
        }
    }

    private function stockDocument(string $reference): GoodsReceipt|SupplierReturn|null
    {
        return match (true) {
            str_starts_with($reference, 'GRN-') => GoodsReceipt::where('grn_number', $reference)->first(),
            str_starts_with($reference, 'SR-') => SupplierReturn::where('return_number', $reference)->first(),
            default => null,
        };
    }

    /** Kapag tinanggap ng INV ang supplier return, Returned na ito. */
    private function returnedStatus(string $reference): array
    {
        return str_starts_with($reference, 'SR-') ? ['status' => SupplierReturn::RETURNED] : [];
    }
}
