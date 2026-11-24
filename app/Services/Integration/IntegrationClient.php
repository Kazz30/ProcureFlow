<?php

namespace App\Services\Integration;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * B7 – Tumatawag sa ibang system.
 *   Http::withHeaders(['X-API-KEY' => ...])->acceptJson()->timeout(10)
 * Lahat ng tawag ay isinusulat sa integration_logs (direction = out).
 *
 * Hindi ito nagtatapon ng exception kapag down ang kabilang system:
 * ibinabalik ang IntegrationResult na may status = null at may error.
 */
class IntegrationClient
{
    public function send(string $system, string $method, string $endpoint, array $payload = [], ?string $referenceNumber = null): IntegrationResult
    {
        $config = config("integration.systems.$system");
        $baseUrl = rtrim((string) ($config['url'] ?? ''), '/');
        $url = $baseUrl.'/'.ltrim($endpoint, '/');
        $method = strtoupper($method);
        $started = microtime(true);

        if ($baseUrl === '') {
            $result = new IntegrationResult(null, null, "Walang {$system}_API_URL sa .env");
        } else {
            try {
                $request = Http::withHeaders(['X-API-KEY' => (string) ($config['key'] ?? '')])
                    ->acceptJson()
                    ->timeout((int) config('integration.timeout', 10));

                $response = $method === 'GET'
                    ? $request->get($url, $payload)
                    : $request->send($method, $url, ['json' => $payload]);

                $result = new IntegrationResult(
                    $response->status(),
                    $response->json(),
                    $response->successful() ? null : $this->errorFrom($response->json(), $response->status()),
                );
            } catch (ConnectionException $e) {
                $result = new IntegrationResult(null, null, 'Hindi maabot: '.$e->getMessage());
            }
        }

        $this->log($system, $method, $url, $referenceNumber, $payload, $result, $started);

        return $result;
    }

    public function get(string $system, string $endpoint, array $query = []): IntegrationResult
    {
        return $this->send($system, 'GET', $endpoint, $query);
    }

    private function errorFrom(mixed $body, int $status): string
    {
        $message = is_array($body) ? ($body['message'] ?? null) : null;
        $errors = is_array($body) && ! empty($body['errors']) ? ' '.json_encode($body['errors'], JSON_UNESCAPED_SLASHES) : '';

        return "HTTP $status".($message ? ": $message" : '').$errors;
    }

    private function log(string $system, string $method, string $url, ?string $ref, array $payload, IntegrationResult $result, float $started): void
    {
        try {
            IntegrationLog::create([
                'direction' => 'out',
                'system' => $system,
                'method' => $method,
                'url' => substr($url, 0, 500),
                'reference_number' => $ref,
                'request_body' => $method === 'GET' ? null : $payload,
                'status_code' => $result->status,
                'response_body' => is_array($result->body) ? $result->body : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $result->error,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
