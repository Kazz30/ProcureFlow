<?php

namespace Tests\Feature\Integration;

use App\Models\GoodsReceipt;
use App\Models\IntegrationOutbox;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\PurchaseOrderFromRequest;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A4 Tests – Hakbang 10 (F5):
 *   GRN → PURCHASE_IN sa outbox; supplier return → SUPPLIER_RETURN_OUT.
 */
class StockMovementOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const INV_URL = 'http://inv.test/api/integration/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        config([
            'integration.enabled' => false,
            'integration.systems.INV.url' => self::INV_URL,
            'integration.systems.INV.key' => 'inv-key',
        ]);
    }

    private function as(string $employeeNo): static
    {
        return $this->actingAs(User::where('employee_no', $employeeNo)->first(), 'sanctum');
    }

    /** PO na Sent: SKU-0001 × 50, BR-001 → WH-001, SUP-0001. */
    private function sentPo(): PurchaseOrder
    {
        $pr = PurchaseRequest::createWithItems([
            'source_system' => 'PROC', 'branch_code' => 'BR-001',
            'items' => [['sku' => 'SKU-0001', 'quantity' => 50]],
        ]);
        $pr->update(['status' => PurchaseRequest::APPROVED]);
        $po = app(PurchaseOrderFromRequest::class)->convert($pr, [], 'EMP-0008')->first();
        $po->update(['status' => PurchaseOrder::SENT]);

        return $po->load('items');
    }

    private function receive(PurchaseOrder $po, int $qty)
    {
        return $this->as('EMP-0008')->postJson("/api/purchase-orders/{$po->id}/receive", [
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'quantity' => $qty]],
        ]);
    }

    private function returnFromGrn(string $grn, int $qty)
    {
        return $this->as('EMP-0008')->postJson('/api/supplier-returns', [
            'grn_number' => $grn, 'reason' => 'Sira',
            'items' => [['sku' => 'SKU-0001', 'quantity' => $qty]],
        ]);
    }

    public function test_grn_puts_purchase_in_on_the_outbox(): void
    {
        $po = $this->sentPo();
        $grn = $this->receive($po, 20)->assertCreated()->json('data.grn_number');

        $row = IntegrationOutbox::sole();
        $this->assertSame('INV', $row->target_system);
        $this->assertSame('POST', $row->method);
        $this->assertSame('/stock-movements', $row->endpoint);
        $this->assertSame($grn, $row->reference_number);
        $this->assertSame('pending', $row->status);

        // Eksaktong JSON sa A4.
        $this->assertSame([
            'movement_type' => 'PURCHASE_IN',
            'source_system' => 'PROC',
            'reference_number' => $grn,
            'branch_code' => 'BR-001',
            'warehouse_code' => 'WH-001',
            'performed_by' => 'EMP-0008',
            'items' => [['sku' => 'SKU-0001', 'quantity' => 20]],
            'remarks' => "Receipt for {$po->po_number}",
        ], $row->payload);

        $this->assertSame('pending', GoodsReceipt::sole()->stock_sync_status);
    }

    public function test_each_partial_receipt_is_its_own_movement(): void
    {
        $po = $this->sentPo();
        $this->receive($po, 20)->assertCreated();
        $this->receive($po, 30)->assertCreated();

        $this->assertSame(2, IntegrationOutbox::count());
        $this->assertSame([20, 30], IntegrationOutbox::orderBy('id')->get()->map(fn ($r) => $r->payload['items'][0]['quantity'])->all());
    }

    public function test_failed_receipt_puts_nothing_on_the_outbox(): void
    {
        $po = $this->sentPo();
        $this->receive($po, 999)->assertStatus(422);

        $this->assertSame(0, IntegrationOutbox::count());   // parehong transaction → rollback
    }

    public function test_supplier_return_puts_supplier_return_out_on_the_outbox(): void
    {
        $po = $this->sentPo();
        $grn = $this->receive($po, 20)->json('data.grn_number');
        $sr = $this->returnFromGrn($grn, 3)->assertCreated()->json('data.return_number');

        $row = IntegrationOutbox::where('reference_number', $sr)->sole();
        $this->assertSame('SUPPLIER_RETURN_OUT', $row->payload['movement_type']);
        $this->assertSame($sr, $row->payload['reference_number']);
        $this->assertSame('WH-001', $row->payload['warehouse_code']);
        $this->assertSame([['sku' => 'SKU-0001', 'quantity' => 3]], $row->payload['items']);
        $this->assertSame('pending', SupplierReturn::sole()->stock_sync_status);
    }

    public function test_outbox_stays_pending_while_integration_is_disabled(): void
    {
        Http::fake();
        $po = $this->sentPo();
        $this->receive($po, 20);

        $this->artisan('integration:process-outbox')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('pending', IntegrationOutbox::sole()->status);
    }

    public function test_full_flow_when_enabled_grn_synced_and_return_422_failed_with_error(): void
    {
        $po = $this->sentPo();
        $grn = $this->receive($po, 20)->json('data.grn_number');
        $srId = $this->returnFromGrn($grn, 3)->json('data.id');

        config(['integration.enabled' => true]);
        Http::fake(function ($request) {
            return $request['movement_type'] === 'PURCHASE_IN'
                ? Http::response(['success' => true, 'data' => ['movement_number' => 'SM-20261125-0001', 'status' => 'posted']], 201)
                : Http::response(['success' => false, 'message' => 'Insufficient stock',
                    'errors' => ['items.0.quantity' => ['Only 1 left for SKU-0001 in WH-001']]], 422);
        });

        $this->artisan('integration:process-outbox')->assertSuccessful();

        $this->assertDatabaseHas('goods_receipts', ['grn_number' => $grn, 'stock_sync_status' => 'synced', 'movement_number' => 'SM-20261125-0001']);

        $sr = SupplierReturn::find($srId);
        $this->assertSame('failed', $sr->stock_sync_status);
        $this->assertSame('Pending', $sr->status);

        // Makikita ng user ang error sa detail ng return.
        $this->as('EMP-0008')->getJson("/api/supplier-returns/$srId")
            ->assertOk()
            ->assertJsonPath('data.stock_outbox.status', 'failed')
            ->assertJsonPath('data.stock_outbox.last_error', fn ($e) => str_contains($e, 'Only 1 left for SKU-0001'));

        // Puwede nang i-cancel ang failed, at nabubura ang row sa outbox.
        $this->as('EMP-0008')->postJson("/api/supplier-returns/$srId/cancel")->assertOk()->assertJsonPath('data.status', 'Cancelled');
        $this->assertSame(0, IntegrationOutbox::where('reference_number', $sr->return_number)->count());
    }

    public function test_synced_return_becomes_returned(): void
    {
        $po = $this->sentPo();
        $grn = $this->receive($po, 20)->json('data.grn_number');
        $srId = $this->returnFromGrn($grn, 3)->json('data.id');

        config(['integration.enabled' => true]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['movement_number' => 'SM-X', 'status' => 'posted']], 201)]);
        $this->artisan('integration:process-outbox')->assertSuccessful();

        $sr = SupplierReturn::find($srId);
        $this->assertSame('synced', $sr->stock_sync_status);
        $this->assertSame('Returned', $sr->status);
        $this->as('EMP-0008')->postJson("/api/supplier-returns/$srId/cancel")->assertStatus(422);
    }

    public function test_pending_return_can_be_cancelled_while_disabled_and_removes_outbox_row(): void
    {
        $po = $this->sentPo();
        $grn = $this->receive($po, 20)->json('data.grn_number');
        $res = $this->returnFromGrn($grn, 3);

        $this->as('EMP-0008')->postJson('/api/supplier-returns/'.$res->json('data.id').'/cancel')->assertOk();

        $this->assertSame(0, IntegrationOutbox::where('reference_number', $res->json('data.return_number'))->count());
        $this->assertSame(1, IntegrationOutbox::count());   // ang GRN lang ang natira
    }
}
