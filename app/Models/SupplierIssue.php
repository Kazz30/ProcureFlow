<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplier issue (SI-YYYYMMDD-0001): reklamo sa produkto, galing CRM (F7).
 */
class SupplierIssue extends Model
{
    public const OPEN = 'Open';
    public const IN_REVIEW = 'In Review';
    public const RESOLVED = 'Resolved';
    public const CLOSED = 'Closed';

    public const STATUSES = [self::OPEN, self::IN_REVIEW, self::RESOLVED, self::CLOSED];

    /** B9: ang tanging tinatanggap na issue_type. */
    public const ISSUE_TYPES = ['Defective', 'Expired', 'Wrong Item', 'Damaged Packaging', 'Other'];

    protected $fillable = [
        'issue_number',
        'source_system',
        'reference_number',
        'sku',
        'supplier_code',
        'issue_type',
        'description',
        'quantity_affected',
        'branch_code',
        'reported_at',
        'status',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'quantity_affected' => 'integer',
            'reported_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_code', 'supplier_code');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }
}
