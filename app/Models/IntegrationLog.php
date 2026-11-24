<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Isang row sa integration_logs (B7): isang tawag papasok (in) o palabas (out).
 */
class IntegrationLog extends Model
{
    // created_at lang ang mayroon ang table; walang updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'direction',
        'system',
        'method',
        'url',
        'reference_number',
        'request_body',
        'status_code',
        'response_body',
        'duration_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'request_body' => 'array',
            'response_body' => 'array',
        ];
    }
}
