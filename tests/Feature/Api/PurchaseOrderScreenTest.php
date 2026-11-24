<?php

namespace Tests\Feature\Api;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hakbang 7c – PR → PO at Purchase Orders screen.
 */
class PurchaseOrderScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    private function as(string $employeeNo): static
    {
        return $this->actingAs(User::where('employee_no', $employeeNo)->first(), 'sanctum');
    }

    /** PR na Approved: SKU-0001 (SUP-0001), SKU-0002 (SUP-0001), SKU-0006 (SUP-0003). */
    private function approvedPr(string $branch = 'BR-001'): PurchaseRequest
    {
        $pr = PurchaseRequest::createWithItems([
            'source_system' => 'INV', 'reference_number' => 'LSA-TEST-'.uniqid(), 'branch_code' => $branch,
            'items' => [
                ['sku' => 'SKU-0001', 'quantity' => 50],
                ['sku' => 'SKU-0002', 'quantity' => 60],
                ['sku' => 'SKU-0006', 'quantity' => 200],
            ],
        ]);
        $pr->update(['status' => PurchaseRequest::APPROVED]);

        return $pr;
    }

    public function test_draft_uses_preferred_supplier_cost_and_default_warehouse(): void
    {
        $pr = $this->approvedPr('BR-002');

        $draft = $this->as('EMP-0008')->getJson("/api/purchase-requests/{$pr->id}/po-draft")->assertOk()->json('data');

        $this->assertSame('WH-002', $draft['warehouse_code']);       // default warehouse ng BR-002
        $this->assertSame('SUP-0001', $draft['items'][0]['supplier_code']);
        $this->assertSame('270.00', $draft['items'][0]['unit_cost']);
        $this->assertSame('SUP-0003', $draft['items'][2]['supplier_code']);
    }

    public function test_convert_splits_by_supplier_and_marks_pr_converted(): void
    {
        $pr = $this->approvedPr();

        $res = $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert")->assertCreated();

        $this->assertCount(2, $res->json('data'));                   // SUP-0001 at SUP-0003
        $this->assertSame('Converted', $pr->fresh()->status);

        $sup1 = PurchaseOrder::where('supplier_code', 'SUP-0001')->first();
        $this->assertSame($pr->pr_number, $sup1->pr_number);
        $this->assertSame('WH-001', $sup1->warehouse_code);
        $this->assertSame('Draft', $sup1->status);
        $this->assertSame('EMP-0008', $sup1->ordered_by);
        $this->assertSame(2, $sup1->items()->count());
        $this->assertSame('270.00', $sup1->items()->where('sku', 'SKU-0001')->first()->unit_cost);
        $this->assertMatchesRegularExpression('/^PO-\d{8}-000[12]$/', $sup1->po_number);
    }

    public function test_user_can_choose_another_supplier_with_a_price(): void
    {
        $pr = $this->approvedPr();
        $item = $pr->items->firstWhere('sku', 'SKU-0006');

        // Bigyan ng presyo ang SUP-0001 para sa SKU-0006, tapos piliin siya → isang PO na lang.
        \DB::table('supplier_products')->insert([
            'supplier_code' => 'SUP-0001', 'sku' => 'SKU-0006', 'unit_cost' => 13.5, 'lead_time_days' => 3, 'is_preferred' => false,
        ]);

        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert", [
            'items' => [['purchase_request_item_id' => $item->id, 'supplier_code' => 'SUP-0001']],
        ])->assertCreated()->assertJsonCount(1, 'data');

        $this->assertSame('13.50', PurchaseOrder::first()->items()->where('sku', 'SKU-0006')->first()->unit_cost);
    }

    public function test_supplier_without_price_is_rejected(): void
    {
        $pr = $this->approvedPr();
        $item = $pr->items->firstWhere('sku', 'SKU-0006');

        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert", [
            'items' => [['purchase_request_item_id' => $item->id, 'supplier_code' => 'SUP-0005']],
        ])->assertStatus(422);

        $this->assertSame('Approved', $pr->fresh()->status);         // walang nabago (rollback)
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_only_approved_pr_can_be_converted_and_only_once(): void
    {
        $pr = $this->approvedPr();
        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert")->assertCreated();

        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert")
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_po_status_flow(): void
    {
        $pr = $this->approvedPr();
        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert")->assertCreated();
        $po = PurchaseOrder::first();

        $this->as('EMP-0008')->postJson("/api/purchase-orders/{$po->id}/approve")->assertForbidden();
        $this->as('EMP-0002')->postJson("/api/purchase-orders/{$po->id}/approve")->assertOk()->assertJsonPath('data.status', 'Approved');
        $this->as('EMP-0008')->postJson("/api/purchase-orders/{$po->id}/send")->assertOk()->assertJsonPath('data.status', 'Sent');
        $this->as('EMP-0002')->postJson("/api/purchase-orders/{$po->id}/approve")->assertStatus(422);
    }

    public function test_cannot_cancel_po_with_received_items(): void
    {
        $pr = $this->approvedPr();
        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert")->assertCreated();
        $po = PurchaseOrder::first();
        $po->update(['status' => PurchaseOrder::SENT]);
        $po->items()->first()->update(['received_quantity' => 5]);

        $this->as('EMP-0008')->postJson("/api/purchase-orders/{$po->id}/cancel")->assertStatus(422);
    }

    public function test_list_has_totals(): void
    {
        $pr = $this->approvedPr();
        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/convert")->assertCreated();

        $row = collect($this->as('EMP-0002')->getJson('/api/purchase-orders?supplier_code=SUP-0001')->assertOk()->json('data'))->first();

        // 50 × 270 + 60 × 78 = 18,180
        $this->assertEquals(18180, (float) $row['total_amount']);
        $this->assertEquals(110, (int) $row['total_quantity']);
    }
}
