<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier return (SR-YYYYMMDD-0001): mga item na ibinabalik sa supplier.
 *
 * MUNGKAHI (wala sa Bahagi A ang mga value ng status):
 *   Pending   → bagong gawa; hinihintay na tanggapin ng INV ang SUPPLIER_RETURN_OUT
 *   Returned  → tinanggap ng INV (synced)
 *   Cancelled → kinansela habang not_sent o failed
 */
class SupplierReturn extends Model
{
    public const PENDING = 'Pending';
    public const RETURNED = 'Returned';
    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::PENDING, self::RETURNED, self::CANCELLED];

    protected $fillable = [
        'return_number',
        'supplier_code',
        'grn_number',
        'warehouse_code',
        'branch_code',
        'reason',
        'returned_by',
        'status',
        'stock_sync_status',
        'movement_number',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_code', 'supplier_code');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'grn_number', 'grn_number');
    }
}
