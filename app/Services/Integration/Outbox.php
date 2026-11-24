<?php

namespace App\Services\Integration;

use App\Models\IntegrationOutbox;

/**
 * B7 – Outbox::push($targetSystem, $method, $endpoint, $payload, $referenceNumber)
 * Naglalagay ng row sa integration_outbox (status = pending). HINDI pa nagpapadala;
 * ang ProcessOutbox ang magpapadala. Kaya ligtas itong tawagin sa loob ng
 * DB::transaction() ng GRN: kapag nag-rollback ang GRN, nawawala rin ang row.
 *
 * Kapag na-push na ang parehong target + endpoint + reference_number,
 * ibinabalik lang ang dati (walang doble).
 */
class Outbox
{
    public static function push(string $targetSystem, string $method, string $endpoint, array $payload, string $referenceNumber): IntegrationOutbox
    {
        return IntegrationOutbox::firstOrCreate(
            [
                'target_system' => $targetSystem,
                'endpoint' => $endpoint,
                'reference_number' => $referenceNumber,
            ],
            [
                'method' => strtoupper($method),
                'payload' => $payload,
                'status' => IntegrationOutbox::PENDING,
                'attempts' => 0,
            ],
        );
    }
}
