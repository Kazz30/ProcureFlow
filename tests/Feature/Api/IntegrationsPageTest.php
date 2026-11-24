<?php

namespace Tests\Feature\Api;

use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\User;
use App\Services\Integration\Outbox;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hakbang 12 – Integrations page (Admin).
 */
class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        config([
            'integration.system_code' => 'PROC',
            'integration.systems.CRM.url' => 'http://crm.test/api/integration/v1',
            'integration.systems.CRM.key' => 'crm-secret',
            'integration.systems.INV.url' => 'http://inv.test/api/integration/v1',
            'integration.systems.INV.key' => 'inv-secret',
            'integration.systems.HR.url' => '',
            'integration.systems.PROC.url' => '',
        ]);
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::where('employee_no', 'EMP-0001')->first(), 'sanctum');
    }

    public function test_only_admin_can_open_the_page(): void
    {
        $this->actingAs(User::where('employee_no', 'EMP-0008')->first(), 'sanctum')
            ->getJson('/api/integrations/health')->assertForbidden();
        $this->actingAs(User::where('employee_no', 'EMP-0002')->first(), 'sanctum')
            ->getJson('/api/integrations/outbox')->assertForbidden();
    }

    public function test_health_shows_green_red_and_not_configured_without_keys(): void
    {
        Http::fake([
            'crm.test/*' => Http::response(['success' => true, 'system' => 'CRM', 'version' => 'v1', 'time' => '2026-11-25T10:00:00+08:00']),
            'inv.test/*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $res = $this->asAdmin()->getJson('/api/integrations/health')->assertOk();
        $systems = collect($res->json('data.systems'))->keyBy('system');

        $this->assertTrue($systems['CRM']['ok']);
        $this->assertSame(200, $systems['CRM']['status_code']);
        $this->assertFalse($systems['INV']['ok']);
        $this->assertStringContainsString('Connection refused', $systems['INV']['error']);
        $this->assertFalse($systems['HR']['configured']);
        $this->assertNull($systems['HR']['ok']);
        $this->assertTrue($systems['PROC']['is_self']);
        $this->assertTrue($systems['PROC']['ok']);

        // Hindi kailanman ibinabalik ang mga key.
        $this->assertStringNotContainsString('crm-secret', $res->getContent());
        $this->assertStringNotContainsString('inv-secret', $res->getContent());

        Http::assertSent(fn (Request $r) => $r->url() === 'http://crm.test/api/integration/v1/health');
    }

    public function test_outbox_list_with_counts_and_filter(): void
    {
        Outbox::push('INV', 'POST', '/stock-movements', ['a' => 1], 'GRN-1');
        Outbox::push('INV', 'POST', '/stock-movements', ['a' => 2], 'GRN-2')->update(['status' => 'failed']);
        Outbox::push('INV', 'POST', '/stock-movements', ['a' => 3], 'GRN-3')->update(['status' => 'sent']);

        $this->asAdmin()->getJson('/api/integrations/outbox')
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('counts', ['pending' => 1, 'sent' => 1, 'failed' => 1]);

        $this->asAdmin()->getJson('/api/integrations/outbox?status=failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_number', 'GRN-2');
    }

    public function test_retry_puts_failed_back_to_pending_and_updates_the_grn(): void
    {
        DB::table('purchase_orders')->insert([
            'po_number' => 'PO-1', 'supplier_code' => 'SUP-0001', 'branch_code' => 'BR-001', 'warehouse_code' => 'WH-001', 'status' => 'Received',
        ]);
        DB::table('goods_receipts')->insert([
            'grn_number' => 'GRN-20261125-0009', 'po_number' => 'PO-1', 'warehouse_code' => 'WH-001', 'branch_code' => 'BR-001', 'stock_sync_status' => 'failed',
        ]);
        $row = Outbox::push('INV', 'POST', '/stock-movements', ['x' => 1], 'GRN-20261125-0009');
        $row->update(['status' => 'failed', 'attempts' => 5, 'last_error' => 'HTTP 500']);

        $this->asAdmin()->postJson("/api/integrations/outbox/{$row->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.attempts', 0);

        $this->assertDatabaseHas('goods_receipts', ['grn_number' => 'GRN-20261125-0009', 'stock_sync_status' => 'pending']);
    }

    public function test_only_failed_can_be_retried(): void
    {
        $row = Outbox::push('INV', 'POST', '/stock-movements', ['x' => 1], 'GRN-1');
        $row->update(['status' => 'sent']);

        $this->asAdmin()->postJson("/api/integrations/outbox/{$row->id}/retry")->assertStatus(422);
        $this->assertSame('sent', IntegrationOutbox::find($row->id)->status);
    }

    public function test_logs_show_latest_100_with_filters(): void
    {
        foreach (range(1, 105) as $i) {
            IntegrationLog::create(['direction' => $i % 2 ? 'in' : 'out', 'system' => 'INV', 'method' => 'GET', 'url' => "/x/$i", 'status_code' => 200]);
        }

        $all = $this->asAdmin()->getJson('/api/integrations/logs')->assertOk()->assertJsonCount(100, 'data');
        $this->assertSame('/x/105', $all->json('data.0.url'));       // pinakabago muna

        $this->asAdmin()->getJson('/api/integrations/logs?direction=out')->assertOk()->assertJsonPath('data.0.direction', 'out');
    }
}
