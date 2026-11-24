<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Presyo ng isang supplier para sa isang SKU, at kung siya ang preferred supplier.
 * Isa lang ang preferred bawat SKU (may unique na preferred_sku sa database).
 */
class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_code',
        'sku',
        'unit_cost',
        'lead_time_days',
        'is_preferred',
    ];

    // preferred_sku ay ginagawa ng database; huwag isama sa save/JSON.
    protected $hidden = ['preferred_sku'];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
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

    /**
     * Ise-save ang record. Kapag preferred, tatanggalin muna ang preferred
     * ng ibang supplier para sa parehong SKU (F6: isa lang ang preferred).
     */
    public function saveKeepingOnePreferred(): void
    {
        DB::transaction(function () {
            if ($this->is_preferred) {
                static::where('sku', $this->sku)
                    ->where('is_preferred', true)
                    ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
                    ->update(['is_preferred' => false]);
            }

            $this->save();
        });
    }

    /** Ang preferred supplier_code ng isang SKU, o null kung wala. */
    public static function preferredSupplierFor(string $sku): ?string
    {
        return static::where('sku', $sku)->where('is_preferred', true)->value('supplier_code');
    }
}
