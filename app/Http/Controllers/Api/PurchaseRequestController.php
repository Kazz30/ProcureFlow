<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A4 – Purchase requests (screen ng PROC).
 */
class PurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $prs = PurchaseRequest::query()
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('source_system'), fn ($q) => $q->where('source_system', $request->source_system))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->search.'%';
                $q->where(fn ($w) => $w->where('pr_number', 'like', $s)->orWhere('reference_number', 'like', $s));
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $prs]);
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        return response()->json([
            'data' => $purchaseRequest->load('items.product:sku,name,unit'),
        ]);
    }

    /** Manual na PR: source_system = PROC, reference_number = sariling pr_number. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_code' => ['required', 'exists:branches,branch_code'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'distinct', 'exists:products,sku'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ], [
            'items.*.sku.distinct' => 'Dalawang beses ang parehong SKU.',
            'items.*.sku.exists' => 'Walang ganitong SKU sa products.',
        ]);

        $pr = PurchaseRequest::createWithItems($data + [
            'source_system' => 'PROC',
            'requested_by' => $request->user()->employee_no,
        ]);

        return response()->json(['data' => $pr->load('items.product:sku,name,unit')], 201);
    }

    /** Manager lang. */
    public function approve(Request $request, PurchaseRequest $purchaseRequest)
    {
        return $this->decide($request, $purchaseRequest, PurchaseRequest::APPROVED);
    }

    /** Manager lang. */
    public function reject(Request $request, PurchaseRequest $purchaseRequest)
    {
        return $this->decide($request, $purchaseRequest, PurchaseRequest::REJECTED);
    }

    private function decide(Request $request, PurchaseRequest $pr, string $status)
    {
        $data = $request->validate([
            'remarks' => [Rule::requiredIf($status === PurchaseRequest::REJECTED), 'nullable', 'string', 'max:1000'],
        ], ['remarks.required' => 'Ilagay kung bakit nire-reject.']);

        $pr = DB::transaction(function () use ($pr, $status, $data, $request) {
            // I-lock para hindi sabay na ma-approve at ma-reject.
            $pr = PurchaseRequest::lockForUpdate()->findOrFail($pr->id);

            if ($pr->status !== PurchaseRequest::PENDING) {
                throw ValidationException::withMessages([
                    'status' => ["Pending lang ang puwedeng i-approve o i-reject (ngayon: {$pr->status})."],
                ]);
            }

            $pr->update([
                'status' => $status,
                'approved_by' => $request->user()->employee_no,
                'approved_at' => now(),
                'remarks' => $data['remarks'] ?? $pr->remarks,
            ]);

            return $pr;
        });

        return response()->json(['data' => $pr->load('items.product:sku,name,unit')]);
    }
}
