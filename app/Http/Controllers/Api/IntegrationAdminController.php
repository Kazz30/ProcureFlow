<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Services\Integration\IntegrationClient;
use App\Services\Integration\OutboxResponseHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A4 – Integrations page (Settings, Admin lang).
 *   - Health ng bawat system (green/red)
 *   - Outbox na may filter na status at "Retry" button
 *   - Ang huling 100 na integration logs
 * Hindi kailanman ibinabalik ang mga API key.
 */
class IntegrationAdminController extends Controller
{
    private const SYSTEMS = ['CRM', 'PROC', 'INV', 'HR'];

    public function health(IntegrationClient $client)
    {
        $systems = collect(self::SYSTEMS)->map(function (string $code) use ($client) {
            $url = (string) config("integration.systems.$code.url");
            $self = $code === config('integration.system_code');

            if ($url === '') {
                return [
                    'system' => $code,
                    'is_self' => $self,
                    'configured' => false,
                    // Ang sarili: buhay tayo kung nasasagot ang request na ito.
                    'ok' => $self ? true : null,
                    'status_code' => null,
                    'url' => null,
                    'time' => $self ? now()->toIso8601String() : null,
                    'error' => $self ? null : "Wala pang {$code}_API_URL sa .env",
                    'duration_ms' => null,
                ];
            }

            $started = microtime(true);
            $result = $client->get($code, '/health');

            return [
                'system' => $code,
                'is_self' => $self,
                'configured' => true,
                'ok' => $result->successful() && data_get($result->body, 'success') === true,
                'status_code' => $result->status,
                'url' => $url,
                'time' => data_get($result->body, 'time'),
                'error' => $result->error,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        });

        return response()->json([
            'data' => [
                'system_code' => config('integration.system_code'),
                'enabled' => (bool) config('integration.enabled'),
                'has_own_key' => (string) config('integration.api_key') !== '',
                'systems' => $systems,
            ],
        ]);
    }

    public function outbox(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in([IntegrationOutbox::PENDING, IntegrationOutbox::SENT, IntegrationOutbox::FAILED])],
            'target_system' => ['nullable', Rule::in(self::SYSTEMS)],
        ]);

        $rows = IntegrationOutbox::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('target_system'), fn ($q) => $q->where('target_system', $request->target_system))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $counts = IntegrationOutbox::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => $rows,
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'sent' => (int) ($counts['sent'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
            ],
        ]);
    }

    /** "Retry" button: ibalik ang failed sa pending (B7). */
    public function retry(IntegrationOutbox $outbox, OutboxResponseHandler $handler)
    {
        $outbox = DB::transaction(function () use ($outbox, $handler) {
            $row = IntegrationOutbox::lockForUpdate()->findOrFail($outbox->id);

            if ($row->status !== IntegrationOutbox::FAILED) {
                throw ValidationException::withMessages([
                    'status' => ['Failed lang ang puwedeng i-retry.'],
                ]);
            }

            $row->retry();
            $handler->retried($row);   // hal. ibalik sa "pending" ang GRN o supplier return

            return $row;
        });

        return response()->json(['data' => $outbox->fresh(), 'message' => 'Ibinalik sa pending. Ipapadala sa susunod na takbo ng scheduler.']);
    }

    public function logs(Request $request)
    {
        $request->validate([
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'system' => ['nullable', 'string', 'max:10'],
        ]);

        return response()->json([
            'data' => IntegrationLog::query()
                ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->direction))
                ->when($request->filled('system'), fn ($q) => $q->where('system', $request->system))
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }
}
