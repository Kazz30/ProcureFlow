<?php

namespace App\Models;

use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Purchase request (PR-YYYYMMDD-0001).
 * Galing INV (low-stock, F4) o ginawa nang manual sa PROC.
 */
class PurchaseRequest extends Model
{
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const CONVERTED = 'Converted';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CONVERTED];

    protected $fillable = [
        'pr_number',
        'source_system',
        'reference_number',
        'branch_code',
        'requested_by',
        'needed_by',
        'status',
        'approved_by',
        'approved_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    /**
     * Gumagawa ng PR at mga item sa iisang transaction.
     * Ginagamit ng screen (manual, source PROC) at ng integration endpoint (galing INV).
     *
     * Kapag PROC ang source at walang reference_number, ang sariling pr_number ang reference (A4).
     *
     * @param  array{source_system:string, reference_number?:string|null, branch_code:string,
     *               requested_by?:string|null, needed_by?:string|null, remarks?:string|null,
     *               items: array<int, array{sku:string, quantity:int, reason?:string|null}>}  $data
     */
    public static function createWithItems(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $prNumber = DocumentNumber::next('PR');

            $pr = static::create([
                'pr_number' => $prNumber,
                'source_system' => $data['source_system'],
                'reference_number' => $data['reference_number'] ?? $prNumber,
                'branch_code' => $data['branch_code'],
                'requested_by' => $data['requested_by'] ?? null,
                'needed_by' => $data['needed_by'] ?? null,
                'status' => self::PENDING,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $pr->items()->create([
                    'sku' => $item['sku'],
                    'quantity' => (int) $item['quantity'],
                    'reason' => $item['reason'] ?? null,
                ]);
            }

            return $pr->load('items');
        });
    }
}
