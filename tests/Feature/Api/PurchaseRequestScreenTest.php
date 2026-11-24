<?php

namespace Tests\Feature\Api;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\DocumentNumber;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hakbang 7b – Purchase requests screen at DocumentNumber.
 */
class PurchaseRequestScreenTest extends TestCase
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

    private function createPr(): PurchaseRequest
    {
        $id = $this->as('EMP-0008')->postJson('/api/purchase-requests', [
            'branch_code' => 'BR-001',
            'items' => [['sku' => 'SKU-0001', 'quantity' => 10, 'reason' => 'Restock']],
        ])->assertCreated()->json('data.id');

        return PurchaseRequest::find($id);
    }

    public function test_document_numbers_count_up_and_reset_each_day(): void
    {
        Carbon::setTestNow('2026-11-25 09:00:00');
        $a = DB::transaction(fn () => DocumentNumber::next('PR'));
        $b = DB::transaction(fn () => DocumentNumber::next('PR'));
        $po = DB::transaction(fn () => DocumentNumber::next('PO'));

        Carbon::setTestNow('2026-11-26 08:00:00');
        $c = DB::transaction(fn () => DocumentNumber::next('PR'));
        Carbon::setTestNow();

        $this->assertSame('PR-20261125-0001', $a);
        $this->assertSame('PR-20261125-0002', $b);
        $this->assertSame('PO-20261125-0001', $po);   // hiwalay na bilang bawat prefix
        $this->assertSame('PR-20261126-0001', $c);    // bagong araw → 0001 ulit
    }

    public function test_manual_pr_uses_its_own_number_as_reference(): void
    {
        $pr = $this->createPr();

        $this->assertMatchesRegularExpression('/^PR-\d{8}-0001$/', $pr->pr_number);
        $this->assertSame('PROC', $pr->source_system);
        $this->assertSame($pr->pr_number, $pr->reference_number);
        $this->assertSame('Pending', $pr->status);
        $this->assertSame('EMP-0008', $pr->requested_by);
        $this->assertSame(1, $pr->items()->count());
    }

    public function test_pr_validation(): void
    {
        $this->as('EMP-0008')->postJson('/api/purchase-requests', [
            'branch_code' => 'BR-999',
            'items' => [
                ['sku' => 'SKU-9999', 'quantity' => 0],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['branch_code', 'items.0.sku', 'items.0.quantity']);
    }

    public function test_manager_can_approve(): void
    {
        $pr = $this->createPr();

        $this->as('EMP-0002')->postJson("/api/purchase-requests/{$pr->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'Approved')->assertJsonPath('data.approved_by', 'EMP-0002');
    }

    public function test_reject_needs_a_reason(): void
    {
        $pr = $this->createPr();

        $this->as('EMP-0002')->postJson("/api/purchase-requests/{$pr->id}/reject")
            ->assertStatus(422)->assertJsonValidationErrors('remarks');

        $this->as('EMP-0002')->postJson("/api/purchase-requests/{$pr->id}/reject", ['remarks' => 'Sobra pa ang stock'])
            ->assertOk()->assertJsonPath('data.status', 'Rejected');
    }

    public function test_only_pending_can_be_decided(): void
    {
        $pr = $this->createPr();
        $this->as('EMP-0002')->postJson("/api/purchase-requests/{$pr->id}/approve")->assertOk();

        $this->as('EMP-0002')->postJson("/api/purchase-requests/{$pr->id}/reject", ['remarks' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_purchasing_officer_cannot_approve(): void
    {
        $pr = $this->createPr();

        $this->as('EMP-0008')->postJson("/api/purchase-requests/{$pr->id}/approve")->assertForbidden();
    }

    public function test_list_filters_by_status_and_source(): void
    {
        $this->createPr();
        PurchaseRequest::createWithItems([
            'source_system' => 'INV', 'reference_number' => 'LSA-20261125-0001', 'branch_code' => 'BR-001',
            'items' => [['sku' => 'SKU-0002', 'quantity' => 60]],
        ]);

        $this->as('EMP-0002')->getJson('/api/purchase-requests?source_system=INV')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_number', 'LSA-20261125-0001');

        $this->as('EMP-0002')->getJson('/api/purchase-requests?status=Pending')
            ->assertOk()->assertJsonCount(2, 'data');
    }
}
