<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * KOPYA ng employees galing HR (read-only sa PROC).
 * can_login = true lang kung Active o On Leave (B7).
 */
class Employee extends Model
{
    protected $guarded = ['*'];

    /** B7: ang mga employment_status na puwedeng mag-login. */
    public const LOGIN_STATUSES = ['Active', 'On Leave'];

    protected function casts(): array
    {
        return [
            'can_login' => 'boolean',
            'source_updated_at' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
