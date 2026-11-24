<?php

namespace App\Support;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * B5 – Listahan para sa ibang system:
 *   ?updated_since=<ISO date>  → mga record na nagbago mula noon
 *   ?per_page=                 → default 100
 *   ?page=
 * Sagot: {"success":true,"message":"OK","data":{"items":[...],"meta":{"current_page":1,"last_page":1,"total":9}}}
 */
class IntegrationList
{
    public static function respond(Request $request, Builder $query, callable $map, string $orderBy): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 100);
        if ($perPage < 1 || $perPage > 1000) {
            throw ValidationException::withMessages(['per_page' => ['Dapat 1 hanggang 1000.']]);
        }

        if ($request->filled('updated_since')) {
            try {
                // Gawing Asia/Manila (ang timezone ng database) bago ikumpara.
                $since = Carbon::parse($request->query('updated_since'))->setTimezone(config('app.timezone'));
            } catch (InvalidFormatException) {
                throw ValidationException::withMessages(['updated_since' => ['Hindi valid na ISO 8601 na petsa.']]);
            }
            // >= para hindi makaligtaan ang nagbago sa parehong segundo (upsert naman ang kumukuha).
            $query->where('updated_at', '>=', $since);
        }

        $page = $query->orderBy($orderBy)->paginate($perPage);

        return IntegrationResponse::ok([
            'items' => collect($page->items())->map($map)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** B5: ISO 8601 na may +08:00. */
    public static function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->toIso8601String() : null;
    }
}
