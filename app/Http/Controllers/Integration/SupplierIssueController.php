<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\SupplierIssue;
use App\Models\SupplierProduct;
use App\Support\DocumentNumber;
use App\Support\IntegrationResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * F7 – POST /api/integration/v1/supplier-issues (galing CRM).
 *
 * - issue_type: Defective | Expired | Wrong Item | Damaged Packaging | Other (iba → 422)
 * - Kapag null ang supplier_code: gamitin ang preferred supplier ng SKU.
 *   Kapag wala ring preferred: tanggapin pa rin na null, para hindi mawala ang reklamo.
 * - Status: Open. 201 kapag bago, 200 kapag inulit.
 */
class SupplierIssueController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'source_system' => ['required', Rule::in(['CRM', 'PROC', 'INV', 'HR'])],
            'reference_number' => ['required', 'string', 'max:50'],
        ]);

        if ($existing = $this->find($request->source_system, $request->reference_number)) {
            return $this->reply($existing, 200, 'Already received');
        }

        $data = $request->validate([
            // Hindi sinusuri kung nasa products ang sku: baka hindi pa na-sync ang bagong SKU
            // galing INV, at ayaw nating mawala ang reklamo.
            'sku' => ['required', 'string', 'max:20'],
            'supplier_code' => ['nullable', 'exists:suppliers,supplier_code'],
            'issue_type' => ['required', Rule::in(SupplierIssue::ISSUE_TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity_affected' => ['required', 'integer', 'min:1'],
            'branch_code' => ['nullable', 'string', 'max:20'],
            'reported_at' => ['nullable', 'date'],
        ]);

        try {
            $issue = DB::transaction(function () use ($data, $request) {
                return SupplierIssue::create([
                    'issue_number' => DocumentNumber::next('SI'),
                    'source_system' => $request->source_system,
                    'reference_number' => $request->reference_number,
                    'sku' => $data['sku'],
                    'supplier_code' => $data['supplier_code'] ?? SupplierProduct::preferredSupplierFor($data['sku']),
                    'issue_type' => $data['issue_type'],
                    'description' => $data['description'] ?? null,
                    'quantity_affected' => $data['quantity_affected'],
                    'branch_code' => $data['branch_code'] ?? null,
                    'reported_at' => isset($data['reported_at'])
                        ? Carbon::parse($data['reported_at'])->setTimezone(config('app.timezone'))
                        : now(),
                    'status' => SupplierIssue::OPEN,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->reply($this->find($request->source_system, $request->reference_number), 200, 'Already received');
        }

        return $this->reply($issue, 201, 'Supplier issue created');
    }

    private function find(string $source, string $reference): ?SupplierIssue
    {
        return SupplierIssue::where('source_system', $source)->where('reference_number', $reference)->first();
    }

    private function reply(SupplierIssue $issue, int $status, string $message)
    {
        // B9: {"issue_number":"SI-20261123-0002","supplier_code":"SUP-0001","status":"Open"}
        return IntegrationResponse::ok([
            'issue_number' => $issue->issue_number,
            'supplier_code' => $issue->supplier_code,
            'status' => $issue->status,
        ], $message, $status);
    }
}
