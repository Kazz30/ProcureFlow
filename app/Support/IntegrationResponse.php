<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * B5 – iisang format ng sagot ng lahat ng integration endpoint.
 *
 *   ok:    {"success": true,  "message": "...", "data": ...}
 *   error: {"success": false, "message": "...", "errors": {...}}
 */
class IntegrationResponse
{
    public static function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            // (object) para laging {} ang lumabas, hindi [] kapag walang laman.
            'errors' => (object) $errors,
        ], $status);
    }
}
