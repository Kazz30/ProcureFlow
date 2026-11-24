<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Support\IntegrationResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * F4 – POST /api/integration/v1/purchase-requests (galing INV, low-stock).
 *
 * 201 → bagong PR
 * 200 → inulit ang parehong source_system + reference_number; ibinabalik ang dati (walang doble)
 * 422 → hindi kilala ang branch_code o sku, o ang quantity ay hindi integer na ≥ 1
 */
class PurchaseRequestController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'source_system' => ['required', Rule::in(['CRM', 'PROC', 'INV', 'HR'])],
            'reference_number' => ['required', 'string', 'max:50'],
        ]);

        // Inulit? Ibalik lang ang dati.
        if ($existing = $this->find($request->source_system, $request->reference_number)) {
            return $this->reply($existing, 200, 'Already received');
        }

        $data = $request->validate([
            'branch_code' => ['required', 'exists:branches,branch_code'],
            'requested_by' => ['nullable', 'string', 'max:20'],   // puwedeng null (A5)
            'needed_by' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'distinct', 'exists:products,sku'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $pr = PurchaseRequest::createWithItems($data + $request->only('source_system', 'reference_number'));
        } catch (UniqueConstraintViolationException) {
            // Sabay na dumating ang dalawang parehong request: nauna ang isa. Ibalik ang nauna.
            return $this->reply($this->find($request->source_system, $request->reference_number), 200, 'Already received');
        }

        return $this->reply($pr, 201, 'Purchase request created');
    }

    private function find(string $source, string $reference): ?PurchaseRequest
    {
        return PurchaseRequest::where('source_system', $source)->where('reference_number', $reference)->first();
    }

    private function reply(PurchaseRequest $pr, int $status, string $message)
    {
        // B9: {"pr_number":"PR-20261123-0003","status":"Pending"}
        return IntegrationResponse::ok([
            'pr_number' => $pr->pr_number,
            'status' => $pr->status,
        ], $message, $status);
    }
}
