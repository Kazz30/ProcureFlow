<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order (PO-YYYYMMDD-0001).
 * Daloy: Draft → Approved → Sent → Partially Received → Received.
 * Puwedeng i-Cancel habang wala pang na-receive.
 */
class PurchaseOrder extends Model
{
    public const DRAFT = 'Draft';
    public const APPROVED = 'Approved';
    public const SENT = 'Sent';
    public const PARTIALLY_RECEIVED = 'Partially Received';
    public const RECEIVED = 'Received';
    public const CANCELLED = 'Cancelled';

    public const STATUSES = [
        self::DRAFT, self::APPROVED, self::SENT,
        self::PARTIALLY_RECEIVED, self::RECEIVED, self::CANCELLED,
    ];

    /** Mga status na puwede nang i-receive (7d). */
    public const RECEIVABLE = [self::APPROVED, self::SENT, self::PARTIALLY_RECEIVED];

    protected $fillable = [
        'po_number',
        'pr_number',
        'supplier_code',
        'branch_code',
        'warehouse_code',
        'status',
        'ordered_by',
        'ordered_at',
    ];

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_code', 'supplier_code');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_number', 'pr_number');
    }
}
