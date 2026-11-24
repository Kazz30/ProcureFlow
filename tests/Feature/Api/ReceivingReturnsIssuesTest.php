<?php

namespace Tests\Feature\Api;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SupplierIssue;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\PurchaseOrderFromRequest;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hakbang 7d – Goods receipts, supplier returns at supplier issues.
 */
class ReceivingReturnsIssuesTest extends TestCase
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

    /** PO na Sent: SKU-0001 × 50 at SKU-0002 × 60 (parehong SUP-0001), WH-001. */
    private function sentPo(): PurchaseOrder
    {
        $pr = PurchaseRequest::createWithItems([
            'source_system' => 'PROC', 'branch_code' => 'BR-001',
            'items' => [['sku' => 'SKU-0001', 'quantity' => 50], ['sku' => 'SKU-0002', 'quantity' => 60]],
        ]);
        $pr->update(['status' => PurchaseRequest::APPROVED]);
        $po = app(PurchaseOrderFromRequest::class)->convert($pr, [], 'EMP-0008')->first();
        $po->update(['status' => PurchaseOrder::SENT]);

        return $po->load('items');
    }

    private function receive(PurchaseOrder $po, array $qtyBySku)
    {
        return $this->as('EMP-0008')->postJson("/api/purchase-orders/{$po->id}/receive", [
            'items' => $po->items->map(fn ($i) => [
                'purchase_order_item_id' => $i->id, 'quantity' => $qtyBySku[$i->sku] ?? 0,
            ])->all(),
        ]);
    }

    public function test_partial_then_full_receive(): void
    {
        $po = $this->sentPo();

        $first = $this->receive($po, ['SKU-0001' => 20])->assertCreated();
        $this->assertMatchesRegularExpression('/^GRN-\d{8}-0001$/', $first->json('data.grn_number'));
        $this->assertSame('not_sent', $first->json('data.stock_sync_status'));
        $this->assertSame('WH-001', $first->json('data.warehouse_code'));
        $this->assertSame('Partially Received', $po->fresh()->status);
        $this->assertSame(20, $po->items()->where('sku', 'SKU-0001')->value('received_quantity'));

        $this->receive($po, ['SKU-0001' => 30, 'SKU-0002' => 60])->assertCreated();
        $this->assertSame('Received', $po->fresh()->status);
        $this->assertSame(2, GoodsReceipt::count());                  // hiwalay na GRN bawat receive
    }

    public function test_cannot_receive_more_than_ordered(): void
    {
        $po = $this->sentPo();

        $this->receive($po, ['SKU-0001' => 51])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->assertSame(0, GoodsReceipt::count());                  // rollback
        $this->assertSame(0, (int) $po->items()->sum('received_quantity'));
    }

    public function test_cannot_receive_draft_po(): void
    {
        $po = $this->sentPo();
        $po->update(['status' => PurchaseOrder::DRAFT]);

        $this->receive($po, ['SKU-0001' => 1])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_return_from_grn_is_limited_to_received_quantity(): void
    {
        $po = $this->sentPo();
        $grn = $this->receive($po, ['SKU-0001' => 20])->json('data.grn_number');

        $ok = $this->as('EMP-0008')->postJson('/api/supplier-returns', [
            'grn_number' => $grn, 'reason' => 'Sira ang 3 sako',
            'items' => [['sku' => 'SKU-0001', 'quantity' => 3]],
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^SR-\d{8}-0001$/', $ok->json('data.return_number'));
        $this->assertSame('SUP-0001', $ok->json('data.supplier_code'));
        $this->assertSame('WH-001', $ok->json('data.warehouse_code'));
        $this->assertSame('Pending', $ok->json('data.status'));

        // 20 na-receive − 3 naibalik = 17 na lang
        $this->as('EMP-0008')->postJson('/api/supplier-returns', [
            'grn_number' => $grn, 'reason' => 'x', 'items' => [['sku' => 'SKU-0001', 'quantity' => 18]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        // SKU na wala sa GRN
        $this->as('EMP-0008')->postJson('/api/supplier-returns', [
            'grn_number' => $grn, 'reason' => 'x', 'items' => [['sku' => 'SKU-0002', 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.sku');
    }

    public function test_manual_return_and_cancel(): void
    {
        $res = $this->as('EMP-0008')->postJson('/api/supplier-returns', [
            'supplier_code' => 'SUP-0003', 'warehouse_code' => 'WH-002', 'reason' => 'Expired',
            'items' => [['sku' => 'SKU-0007', 'quantity' => 4]],
        ])->assertCreated();

        $this->assertSame('BR-002', $res->json('data.branch_code'));   // galing sa warehouse
        $id = $res->json('data.id');

        $this->as('EMP-0008')->postJson("/api/supplier-returns/$id/cancel")->assertOk()->assertJsonPath('data.status', 'Cancelled');
        $this->as('EMP-0008')->postJson("/api/supplier-returns/$id/cancel")->assertStatus(422);
    }

    public function test_cannot_cancel_return_that_is_pending_in_outbox(): void
    {
        $id = $this->as('EMP-0008')->postJson('/api/supplier-returns', [
            'supplier_code' => 'SUP-0003', 'warehouse_code' => 'WH-001', 'reason' => 'x',
            'items' => [['sku' => 'SKU-0007', 'quantity' => 1]],
        ])->json('data.id');
        SupplierReturn::find($id)->update(['stock_sync_status' => 'pending']);

        $this->as('EMP-0008')->postJson("/api/supplier-returns/$id/cancel")->assertStatus(422);
    }

    public function test_supplier_issue_list_and_update(): void
    {
        $issue = SupplierIssue::create([
            'issue_number' => 'SI-20261125-0001', 'source_system' => 'CRM', 'reference_number' => 'FB-20261125-0001',
            'sku' => 'SKU-0001', 'supplier_code' => 'SUP-0001', 'issue_type' => 'Defective',
            'description' => 'Butas ang sako', 'quantity_affected' => 1, 'branch_code' => 'BR-001',
            'reported_at' => now(), 'status' => 'Open',
        ]);

        $this->as('EMP-0002')->getJson('/api/supplier-issues?status=Open&supplier_code=SUP-0001')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_number', 'FB-20261125-0001');

        $this->as('EMP-0008')->putJson("/api/supplier-issues/{$issue->id}", ['status' => 'Resolved'])
            ->assertStatus(422)->assertJsonValidationErrors('resolution');

        $this->as('EMP-0008')->putJson("/api/supplier-issues/{$issue->id}", ['status' => 'Resolved', 'resolution' => 'Pinalitan ng supplier'])
            ->assertOk()->assertJsonPath('data.status', 'Resolved')->assertJsonPath('data.supplier_code', 'SUP-0001');
    }
}
