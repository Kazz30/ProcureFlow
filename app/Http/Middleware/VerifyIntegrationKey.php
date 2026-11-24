<?php

namespace App\Http\Middleware;

use App\Models\IntegrationLog;
use App\Support\IntegrationResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * B5/B7 – Sinusuri ang X-API-KEY ng bawat papasok na integration request.
 * Alias: integration.key (naka-register sa bootstrap/app.php).
 *
 * Mali o walang key → HTTP 401. Bawat request (pati ang 401) ay isinusulat
 * sa integration_logs na may direction = in.
 */
class VerifyIntegrationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $expected = (string) config('integration.api_key');
        $given = (string) $request->header('X-API-KEY', '');

        // hash_equals: ligtas na paghahambing (hindi nahuhulaan ang key sa bilis ng sagot).
        // Kapag walang naka-set na sariling key, lahat ay tatanggihan.
        if ($expected === '' || ! hash_equals($expected, $given)) {
            $response = IntegrationResponse::error('Invalid or missing API key', [], 401);
        } else {
            $response = $next($request);
        }

        $this->log($request, $response, $started);

        return $response;
    }

    private function log(Request $request, Response $response, float $started): void
    {
        try {
            $responseBody = json_decode((string) $response->getContent(), true);

            IntegrationLog::create([
                'direction' => 'in',
                // Hindi alam kung sino ang tumawag sa GET; sa POST, galing sa source_system.
                'system' => substr((string) $request->input('source_system', 'UNKNOWN'), 0, 10),
                'method' => $request->method(),
                'url' => substr($request->fullUrl(), 0, 500),
                'reference_number' => $request->input('reference_number'),
                'request_body' => $request->isMethod('GET') ? null : $request->all(),
                'status_code' => $response->getStatusCode(),
                'response_body' => is_array($responseBody) ? $responseBody : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            // Hindi dapat masira ang sagot dahil lang sa pag-log.
            report($e);
        }
    }
}
