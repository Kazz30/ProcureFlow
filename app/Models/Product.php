<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * KOPYA ng products galing INV (read-only sa PROC).
 * Ang MasterDataSeeder at integration:sync-masterdata lang ang nagpapalit nito.
 */
class Product extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'reorder_level' => 'integer',
            'reorder_quantity' => 'integer',
            'source_updated_at' => 'datetime',
        ];
    }
}
