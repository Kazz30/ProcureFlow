<?php

namespace App\Console\Commands;

use App\Models\IntegrationOutbox;
use App\Services\Integration\IntegrationClient;
use App\Services\Integration\OutboxResponseHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * B7 – php artisan integration:process-outbox
 * Ipinapadala ang mga pending na row sa integration_outbox, kung INTEGRATION_ENABLED=true.
 * Naka-schedule everyMinute() sa routes/console.php.
 *
 * Retry rule:
 *   2xx              → sent
 *   422              → failed agad (may mali sa data; hindi aayos kahit ulitin)
 *   iba / hindi maabot → attempts + 1; susubok ulit pagkalipas ng 1, 2, 4, 8 minuto;
 *                       pagdating ng 5 attempts → failed
 */
class ProcessOutbox extends Command
{
    protected $signature = 'integration:process-outbox {--limit=50 : Ilan ang ipapadala sa isang takbo}';

    protected $description = 'Ipadala ang mga pending na request sa integration_outbox';

    public function handle(IntegrationClient $client, OutboxResponseHandler $handler): int
    {
        if (! config('integration.enabled')) {
            $this->info('INTEGRATION_ENABLED=false: walang ipapadala; mananatiling pending ang outbox.');

            return self::SUCCESS;
        }

        $rows = IntegrationOutbox::where('status', IntegrationOutbox::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $result = $client->send($row->target_system, $row->method, $row->endpoint, $row->payload, $row->reference_number);
            $attempts = $row->attempts + 1;

            if ($result->successful()) {
                $row->update([
                    'status' => IntegrationOutbox::SENT,
                    'attempts' => $attempts,
                    'sent_at' => now(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                ]);
                $this->safely(fn () => $handler->sent($row, $result->body));
                $counts['sent']++;
            } elseif ($result->isValidationError() || $attempts >= IntegrationOutbox::MAX_ATTEMPTS) {
                $row->update([
                    'status' => IntegrationOutbox::FAILED,
                    'attempts' => $attempts,
                    'last_error' => $result->error,
                    'next_attempt_at' => null,
                ]);
                $this->safely(fn () => $handler->failed($row));
                $counts['failed']++;
            } else {
                $minutes = IntegrationOutbox::RETRY_MINUTES[$attempts - 1];
                $row->update([
                    'attempts' => $attempts,
                    'last_error' => $result->error,
                    'next_attempt_at' => now()->addMinutes($minutes),
                ]);
                $counts['retry']++;
            }

            $this->line(sprintf('  #%d %s %s %s → %s', $row->id, $row->target_system, $row->endpoint, $row->reference_number, $row->status.($row->status === 'pending' ? " (attempt $attempts, ulit sa {$row->next_attempt_at})" : '')));
        }

        $this->info(sprintf('Tapos: %d sent, %d susubukan ulit, %d failed.', $counts['sent'], $counts['retry'], $counts['failed']));

        return self::SUCCESS;
    }

    /** Hindi dapat mapahinto ang buong outbox dahil lang may error sa handler. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
            $this->warn('  Error sa handler: '.$e->getMessage());
        }
    }
}
