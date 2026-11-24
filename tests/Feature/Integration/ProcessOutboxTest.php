<?php

namespace Tests\Feature\Integration;

use App\Models\IntegrationOutbox;
use App\Services\Integration\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A4 Tests – ProcessOutbox gamit ang Http::fake():
 *   2xx → sent; 500 → retry 1/2/4/8 minuto; 422 → failed agad.
 */
class ProcessOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const INV_URL = 'http://inv.test/api/integration/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integration.enabled' => true,
            'integration.systems.INV.url' => self::INV_URL,
            'integration.systems.INV.key' => 'inv-secret-key',
        ]);
        Carbon::setTestNow('2026-11-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pushGrn(string $ref = 'GRN-20261125-0001'): IntegrationOutbox
    {
        return Outbox::push('INV', 'POST', '/stock-movements', [
            'movement_type' => 'PURCHASE_IN', 'source_system' => 'PROC', 'reference_number' => $ref,
            'branch_code' => 'BR-001', 'warehouse_code' => 'WH-001', 'performed_by' => 'EMP-0008',
            'items' => [['sku' => 'SKU-0050', 'quantity' => 20]], 'remarks' => 'Receipt for PO-20261125-0001',
        ], $ref);
    }

    private function processOutbox(): void
    {
        $this->artisan('integration:process-outbox')->assertSuccessful();
    }

    public function test_push_only_queues_and_does_not_duplicate(): void
    {
        Http::fake();

        $a = $this->pushGrn();
        $b = $this->pushGrn();

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, IntegrationOutbox::count());
        $this->assertSame('pending', $a->status);
        Http::assertNothingSent();
    }

    public function test_disabled_integration_sends_nothing_and_stays_pending(): void
    {
        config(['integration.enabled' => false]);
        Http::fake();
        $row = $this->pushGrn();

        $this->processOutbox();

        Http::assertNothingSent();
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_2xx_marks_sent_with_key_header_and_logs(): void
    {
        Http::fake([self::INV_URL.'/stock-movements' => Http::response(
            ['success' => true, 'data' => ['movement_number' => 'SM-20261125-0007', 'status' => 'posted']], 201,
        )]);
        $row = $this->pushGrn();

        $this->processOutbox();

        $row->refresh();
        $this->assertSame('sent', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->sent_at);

        Http::assertSent(fn (Request $r) => $r->url() === self::INV_URL.'/stock-movements'
            && $r->method() === 'POST'
            && $r->header('X-API-KEY')[0] === 'inv-secret-key'
            && $r['movement_type'] === 'PURCHASE_IN'
            && $r['reference_number'] === 'GRN-20261125-0001');

        $this->assertDatabaseHas('integration_logs', [
            'direction' => 'out', 'system' => 'INV', 'method' => 'POST',
            'reference_number' => 'GRN-20261125-0001', 'status_code' => 201,
        ]);
    }

    public function test_500_retries_after_1_2_4_8_minutes_then_fails(): void
    {
        Http::fake([self::INV_URL.'/*' => Http::response(['message' => 'Server error'], 500)]);
        $row = $this->pushGrn();

        foreach ([1, 2, 4, 8] as $attempt => $minutes) {
            $this->processOutbox();
            $row->refresh();

            $this->assertSame('pending', $row->status);
            $this->assertSame($attempt + 1, $row->attempts);
            $this->assertEquals(now()->addMinutes($minutes), $row->next_attempt_at);
            $this->assertStringContainsString('HTTP 500', $row->last_error);

            // Hindi pa oras: walang ipapadala.
            $this->processOutbox();
            $this->assertSame($attempt + 1, $row->fresh()->attempts);

            Carbon::setTestNow(now()->addMinutes($minutes));
        }

        $this->processOutbox();                                         // ika-5 attempt
        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame(5, $row->attempts);
        Http::assertSentCount(5);
    }

    public function test_422_fails_immediately(): void
    {
        Http::fake([self::INV_URL.'/*' => Http::response([
            'success' => false, 'message' => 'Insufficient stock',
            'errors' => ['items.0.quantity' => ['Only 1 left for SKU-0001 in WH-001']],
        ], 422)]);
        $row = $this->pushGrn('SR-20261125-0001');

        $this->processOutbox();

        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('Insufficient stock', $row->last_error);
        $this->assertStringContainsString('Only 1 left', $row->last_error);
    }

    public function test_connection_error_is_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $row = $this->pushGrn();

        $this->processOutbox();

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('Connection refused', $row->last_error);
        $this->assertDatabaseHas('integration_logs', ['direction' => 'out', 'status_code' => null]);
    }

    public function test_missing_url_is_retried_not_crashed(): void
    {
        config(['integration.systems.INV.url' => '']);
        Http::fake();
        $row = $this->pushGrn();

        $this->processOutbox();

        Http::assertNothingSent();
        $this->assertStringContainsString('INV_API_URL', $row->fresh()->last_error);
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_handler_saves_movement_number_on_the_grn(): void
    {
        $this->makeGrn('GRN-20261125-0001');
        Http::fake([self::INV_URL.'/*' => Http::response(['success' => true, 'data' => ['movement_number' => 'SM-20261125-0009']], 201)]);
        $this->pushGrn('GRN-20261125-0001');

        $this->processOutbox();

        $this->assertDatabaseHas('goods_receipts', [
            'grn_number' => 'GRN-20261125-0001', 'stock_sync_status' => 'synced', 'movement_number' => 'SM-20261125-0009',
        ]);
    }

    public function test_handler_marks_grn_failed(): void
    {
        $this->makeGrn('GRN-20261125-0002');
        Http::fake([self::INV_URL.'/*' => Http::response(['success' => false, 'message' => 'Bad'], 422)]);
        $this->pushGrn('GRN-20261125-0002');

        $this->processOutbox();

        $this->assertDatabaseHas('goods_receipts', ['grn_number' => 'GRN-20261125-0002', 'stock_sync_status' => 'failed']);
    }

    public function test_retry_puts_failed_back_to_pending(): void
    {
        $row = $this->pushGrn();
        $row->update(['status' => 'failed', 'attempts' => 5, 'last_error' => 'x']);

        $row->retry();

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->last_error);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('integration:process-outbox')->assertSuccessful();
    }

    private function makeGrn(string $grnNumber): void
    {
        DB::table('suppliers')->insert(['supplier_code' => 'SUP-0001', 'name' => 'S', 'status' => 'Active']);
        DB::table('purchase_orders')->insertOrIgnore([
            'po_number' => 'PO-20261125-0001', 'supplier_code' => 'SUP-0001', 'branch_code' => 'BR-001',
            'warehouse_code' => 'WH-001', 'status' => 'Sent',
        ]);
        DB::table('goods_receipts')->insert([
            'grn_number' => $grnNumber, 'po_number' => 'PO-20261125-0001', 'warehouse_code' => 'WH-001',
            'branch_code' => 'BR-001', 'stock_sync_status' => 'pending',
        ]);
    }
}
