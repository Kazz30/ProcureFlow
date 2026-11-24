<?php

namespace Tests\Feature\Integration;

use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierIssue;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A4 Tests – Hakbang 8: ang 4 na integration endpoint.
 */
class IntegrationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-integration-key';

    private const PR_BODY = [
        'source_system' => 'INV', 'reference_number' => 'LSA-20261123-0004', 'branch_code' => 'BR-001',
        'requested_by' => 'EMP-0007', 'needed_by' => '2026-11-30',
        'items' => [['sku' => 'SKU-0001', 'quantity' => 50, 'reason' => 'Below reorder level']],
    ];

    private const ISSUE_BODY = [
        'source_system' => 'CRM', 'reference_number' => 'FB-20261123-0012', 'sku' => 'SKU-0001', 'supplier_code' => null,
        'issue_type' => 'Defective', 'description' => 'Broken on opening', 'quantity_affected' => 1,
        'branch_code' => 'BR-001', 'reported_at' => '2026-11-23T10:15:00+08:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['integration.api_key' => self::KEY]);
        $this->seed(MasterDataSeeder::class);
    }

    private function api(): static
    {
        return $this->withHeaders(['X-API-KEY' => self::KEY]);
    }

    // ---------- API key ----------

    public function test_all_four_endpoints_need_the_api_key(): void
    {
        $this->getJson('/api/integration/v1/suppliers')->assertStatus(401);
        $this->getJson('/api/integration/v1/supplier-products')->assertStatus(401);
        $this->postJson('/api/integration/v1/purchase-requests', self::PR_BODY)->assertStatus(401);
        $this->postJson('/api/integration/v1/supplier-issues', self::ISSUE_BODY)->assertStatus(401);
        $this->assertSame(0, PurchaseRequest::count());
    }

    // ---------- GET /suppliers ----------

    public function test_suppliers_list_format(): void
    {
        $res = $this->api()->getJson('/api/integration/v1/suppliers')->assertOk();

        $res->assertJson([
            'success' => true,
            'data' => ['meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 5]],
        ]);
        $res->assertJsonPath('data.items.0', [
            'supplier_code' => 'SUP-0001',
            'name' => 'Golden Harvest Trading',
            'contact_person' => 'Ramil Bautista',
            'email' => 'sales@goldenharvest.example.com',
            'phone' => '0917-000-0001',
            'status' => 'Active',
            'updated_at' => $res->json('data.items.0.updated_at'),
        ]);
        $this->assertStringEndsWith('+08:00', $res->json('data.items.0.updated_at'));
        $this->assertArrayNotHasKey('id', $res->json('data.items.0'));        // B4: walang database ID
    }

    public function test_suppliers_updated_since_and_per_page(): void
    {
        DB::table('suppliers')->update(['updated_at' => '2026-11-01 08:00:00']);
        Supplier::where('supplier_code', 'SUP-0003')->first()->forceFill(['updated_at' => '2026-11-20 09:00:00'])->saveQuietly();

        $this->api()->getJson('/api/integration/v1/suppliers?updated_since='.urlencode('2026-11-15T00:00:00+08:00'))
            ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.supplier_code', 'SUP-0003');

        // Ibang timezone sa query: 2026-11-20T00:30Z = 08:30 Manila → kasama pa rin ang 09:00
        $this->api()->getJson('/api/integration/v1/suppliers?updated_since='.urlencode('2026-11-20T00:30:00Z'))
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->api()->getJson('/api/integration/v1/suppliers?per_page=2&page=3')
            ->assertOk()->assertJsonPath('data.meta.last_page', 3)->assertJsonCount(1, 'data.items');

        $this->api()->getJson('/api/integration/v1/suppliers?updated_since=hindi-petsa')
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    // ---------- GET /supplier-products ----------

    public function test_supplier_products_format(): void
    {
        $this->api()->getJson('/api/integration/v1/supplier-products')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 15)
            ->assertJsonPath('data.items.0.supplier_code', 'SUP-0001')
            ->assertJsonPath('data.items.0.sku', 'SKU-0001')
            ->assertJsonPath('data.items.0.unit_cost', '270.00')
            ->assertJsonPath('data.items.0.lead_time_days', 3)
            ->assertJsonPath('data.items.0.is_preferred', true);
    }

    // ---------- POST /purchase-requests ----------

    public function test_purchase_request_is_created(): void
    {
        $res = $this->api()->postJson('/api/integration/v1/purchase-requests', self::PR_BODY)->assertCreated();

        $res->assertJson(['success' => true, 'data' => ['status' => 'Pending']]);
        $this->assertMatchesRegularExpression('/^PR-\d{8}-0001$/', $res->json('data.pr_number'));

        $pr = PurchaseRequest::first();
        $this->assertSame('INV', $pr->source_system);
        $this->assertSame('LSA-20261123-0004', $pr->reference_number);
        $this->assertSame('EMP-0007', $pr->requested_by);
        $this->assertSame(50, $pr->items->first()->quantity);

        $this->assertDatabaseHas('integration_logs', [
            'direction' => 'in', 'system' => 'INV', 'reference_number' => 'LSA-20261123-0004', 'status_code' => 201,
        ]);
    }

    public function test_repeating_the_same_purchase_request_returns_200_without_duplicate(): void
    {
        $first = $this->api()->postJson('/api/integration/v1/purchase-requests', self::PR_BODY)->assertCreated();
        $again = $this->api()->postJson('/api/integration/v1/purchase-requests', self::PR_BODY)->assertOk();

        $this->assertSame($first->json('data.pr_number'), $again->json('data.pr_number'));
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_purchase_request_with_unknown_sku_returns_422(): void
    {
        $body = self::PR_BODY;
        $body['items'][0]['sku'] = 'SKU-9999';

        $this->api()->postJson('/api/integration/v1/purchase-requests', $body)
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors('items.0.sku');
        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_purchase_request_other_422_cases(): void
    {
        foreach ([
            ['branch_code', 'BR-999', 'branch_code'],
            ['items', [['sku' => 'SKU-0001', 'quantity' => 0]], 'items.0.quantity'],
            ['items', [['sku' => 'SKU-0001', 'quantity' => 1.5]], 'items.0.quantity'],
        ] as [$field, $value, $error]) {
            $body = self::PR_BODY;
            $body[$field] = $value;
            $body['reference_number'] = 'LSA-BAD-'.$error;

            $this->api()->postJson('/api/integration/v1/purchase-requests', $body)
                ->assertStatus(422)->assertJsonValidationErrors($error);
        }
    }

    public function test_requested_by_can_be_null(): void
    {
        $body = self::PR_BODY;
        $body['requested_by'] = null;

        $this->api()->postJson('/api/integration/v1/purchase-requests', $body)->assertCreated();
        $this->assertNull(PurchaseRequest::first()->requested_by);
    }

    // ---------- POST /supplier-issues ----------

    public function test_supplier_issue_uses_preferred_supplier_when_null(): void
    {
        $res = $this->api()->postJson('/api/integration/v1/supplier-issues', self::ISSUE_BODY)->assertCreated();

        $res->assertJson(['success' => true, 'data' => ['supplier_code' => 'SUP-0001', 'status' => 'Open']]);
        $this->assertMatchesRegularExpression('/^SI-\d{8}-0001$/', $res->json('data.issue_number'));

        $issue = SupplierIssue::first();
        $this->assertSame('FB-20261123-0012', $issue->reference_number);
        $this->assertSame('2026-11-23 10:15:00', $issue->reported_at->format('Y-m-d H:i:s'));
    }

    public function test_supplier_issue_without_preferred_supplier_is_still_accepted(): void
    {
        $body = self::ISSUE_BODY;
        $body['sku'] = 'SKU-0050';   // bagong SKU, wala pang supplier (at baka hindi pa na-sync)

        $this->api()->postJson('/api/integration/v1/supplier-issues', $body)
            ->assertCreated()->assertJsonPath('data.supplier_code', null);
    }

    public function test_supplier_issue_keeps_given_supplier(): void
    {
        $body = self::ISSUE_BODY;
        $body['supplier_code'] = 'SUP-0002';

        $this->api()->postJson('/api/integration/v1/supplier-issues', $body)
            ->assertCreated()->assertJsonPath('data.supplier_code', 'SUP-0002');
    }

    public function test_repeating_the_same_supplier_issue_returns_200(): void
    {
        $this->api()->postJson('/api/integration/v1/supplier-issues', self::ISSUE_BODY)->assertCreated();
        $this->api()->postJson('/api/integration/v1/supplier-issues', self::ISSUE_BODY)->assertOk();

        $this->assertSame(1, SupplierIssue::count());
    }

    public function test_supplier_issue_rejects_unknown_issue_type(): void
    {
        $body = self::ISSUE_BODY;
        $body['issue_type'] = 'Smelly';

        $this->api()->postJson('/api/integration/v1/supplier-issues', $body)
            ->assertStatus(422)->assertJsonValidationErrors('issue_type');
    }
}
