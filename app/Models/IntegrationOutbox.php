<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Isang row sa integration_outbox (B7): request na ipapadala sa ibang system.
 * status: pending → sent / failed
 */
class IntegrationOutbox extends Model
{
    protected $table = 'integration_outbox';

    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    /** B7: pagkatapos ng ganitong bilang ng attempt, failed na. */
    public const MAX_ATTEMPTS = 5;

    /** B7: hintay bago ang susunod na subok (minuto), ayon sa bilang ng attempt na nag-fail. */
    public const RETRY_MINUTES = [1, 2, 4, 8];

    protected $fillable = [
        'target_system',
        'method',
        'endpoint',
        'payload',
        'reference_number',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** Ang "Retry" button sa admin page: ibalik ang failed sa pending. */
    public function retry(): void
    {
        $this->update([
            'status' => self::PENDING,
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
        ]);
    }
}
