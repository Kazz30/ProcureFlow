<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Goods receipt (GRN-YYYYMMDD-0001): pag-receive ng mga item ng isang PO.
 * stock_sync_status: not_sent → pending (nasa outbox) → synced / failed (sagot ng INV).
 */
class GoodsReceipt extends Model
{
    protected $fillable = [
        'grn_number',
        'po_number',
        'warehouse_code',
        'branch_code',
        'received_by',
        'received_at',
        'stock_sync_status',
        'movement_number',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_number', 'po_number');
    }

    /** Ang PURCHASE_IN sa outbox (para makita ang status at error ng padala sa INV). */
    public function stockOutbox(): HasOne
    {
        return $this->hasOne(IntegrationOutbox::class, 'reference_number', 'grn_number')
            ->where('target_system', 'INV')
            ->where('endpoint', '/stock-movements');
    }
}
