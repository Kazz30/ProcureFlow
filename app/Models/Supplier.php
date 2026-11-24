<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Supplier (sariling data ng PROC). Code: SUP-0001.
 */
class Supplier extends Model
{
    protected $fillable = [
        'supplier_code',
        'name',
        'contact_person',
        'email',
        'phone',
        'status',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'supplier_code', 'supplier_code');
    }

    /**
     * Susunod na supplier_code (SUP-0006, ...). Tawagin sa loob ng DB transaction.
     */
    public static function nextCode(): string
    {
        $last = DB::table('suppliers')
            ->where('supplier_code', 'like', 'SUP-%')
            ->lockForUpdate()
            ->orderByDesc('supplier_code')
            ->value('supplier_code');

        $next = $last ? ((int) substr($last, 4)) + 1 : 1;

        return sprintf('SUP-%04d', $next);
    }
}
