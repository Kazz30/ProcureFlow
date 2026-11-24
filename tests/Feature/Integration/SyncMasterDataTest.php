<?php

namespace Tests\Feature\Integration;

use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Yugto 2 – integration:sync-masterdata gamit ang Http::fake().
 */
class SyncMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private const HR = 'http://hr.test/api/integration/v1';
    private const INV = 'http://inv.test/api/integration/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        config([
            'integration.enabled' => true,
            'integration.api_key' => 'proc-own-key',
            'integration.systems.HR.url' => self::HR,
            'integration.systems.HR.key' => 'hr-key',
            'integration.systems.INV.url' => self::INV,
            'integration.systems.INV.key' => 'inv-key',
        ]);
    }

    /** B5 na listahan. */
    private static function page(array $items, int $page = 1, int $last = 1): array
    {
        return ['success' => true, 'message' => 'OK', 'data' => [
            'items' => $items, 'meta' => ['current_page' => $page, 'last_page' => $last, 'total' => count($items)],
        ]];
    }

    private static function employee(string $no, string $status = 'Active', string $updated = '2026-11-25T09:00:00+08:00'): array
    {
        return [
            'employee_no' => $no, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => strtolower($no).'@example.com',
            'branch_code' => 'BR-001', 'department_code' => 'DEP-002', 'position' => 'Sales Associate',
            'employment_status' => $status, 'can_login' => in_array($status, ['Active', 'On Leave'], true),
            'system_access' => [['system' => 'CRM', 'role' => 'Sales']], 'updated_at' => $updated,
        ];
    }

    /** Lahat ng endpoint ay walang laman maliban sa ibinigay. */
    private function fakeOwners(array $responses = []): void
    {
        Http::fake(function (Request $request) use ($responses) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            foreach ($responses as $suffix => $response) {
                if (str_ends_with($path, $suffix)) {
                    return is_callable($response) ? $response($request) : Http::response($response);
                }
            }

            return Http::response(self::page([]));
        });
    }

    public function test_does_nothing_while_integration_is_disabled(): void
    {
        config(['integration.enabled' => false]);
        Http::fake();

        $this->artisan('integration:sync-masterdata')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_new_employee_and_new_product_are_copied(): void
    {
        $this->fakeOwners([
            '/employees' => self::page([self::employee('EMP-0010')]),
            '/products' => self::page([[
                'sku' => 'SKU-0050', 'name' => 'Corned Beef 150g', 'category' => 'Grocery', 'unit' => 'can',
                'price' => '45.00', 'cost' => '36.00', 'reorder_level' => 10, 'reorder_quantity' => 40,
                'preferred_supplier_code' => null, 'status' => 'Active', 'updated_at' => '2026-11-25T09:05:00+08:00',
            ]]),
        ]);

        $this->artisan('integration:sync-masterdata')->assertSuccessful();

        $this->assertDatabaseHas('employees', ['employee_no' => 'EMP-0010', 'employment_status' => 'Active', 'can_login' => true]);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-0050', 'name' => 'Corned Beef 150g', 'reorder_level' => 10]);
        $this->assertSame('2026-11-25 09:05:00', DB::table('products')->where('sku', 'SKU-0050')->value('source_updated_at'));
        $this->assertSame(0, User::where('employee_no', 'EMP-0010')->count());   // A4: walang awtomatikong user

        // B10 hakbang 4: pagkatapos ng sync, tanggap na ng PROC ang PR ng INV para sa SKU-0050.
        $this->withHeaders(['X-API-KEY' => 'proc-own-key'])->postJson('/api/integration/v1/purchase-requests', [
            'source_system' => 'INV', 'reference_number' => 'LSA-20261125-0001', 'branch_code' => 'BR-001',
            'requested_by' => null, 'items' => [['sku' => 'SKU-0050', 'quantity' => 40]],
        ])->assertCreated();
    }

    public function test_resigned_employee_cannot_log_in_and_tokens_are_deleted(): void
    {
        $grace = User::where('employee_no', 'EMP-0008')->first();
        $grace->createToken('web');
        $this->fakeOwners(['/employees' => self::page([self::employee('EMP-0008', 'Resigned')])]);

        $this->artisan('integration:sync-masterdata')->assertSuccessful();

        $grace->refresh();
        $this->assertFalse($grace->can_login);
        $this->assertSame('Resigned', $grace->employment_status);
        $this->assertSame(0, $grace->tokens()->count());
        $this->postJson('/api/login', ['email' => 'grace.ramos@example.com', 'password' => 'password'])->assertStatus(403);
    }

    public function test_can_login_is_never_true_for_resigned_even_if_hr_says_so(): void
    {
        $bad = self::employee('EMP-0008', 'Terminated');
        $bad['can_login'] = true;
        $this->fakeOwners(['/employees' => self::page([$bad])]);

        $this->artisan('integration:sync-masterdata')->assertSuccessful();

        $this->assertFalse(User::where('employee_no', 'EMP-0008')->first()->can_login);
    }

    public function test_reads_every_page_and_sends_key_and_per_page(): void
    {
        $this->fakeOwners(['/employees' => function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $page = (int) ($q['page'] ?? 1);

            return Http::response(self::page([self::employee(sprintf('EMP-01%02d', $page))], $page, 3));
        }]);

        $this->artisan('integration:sync-masterdata', ['--only' => ['employees']])->assertSuccessful();

        $this->assertDatabaseHas('employees', ['employee_no' => 'EMP-0101']);
        $this->assertDatabaseHas('employees', ['employee_no' => 'EMP-0103']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->header('X-API-KEY')[0] === 'hr-key' && str_contains($r->url(), 'per_page=100'));
    }

    public function test_second_run_asks_only_for_changes_since_the_newest_copy(): void
    {
        $this->fakeOwners(['/employees' => self::page([self::employee('EMP-0010', 'Active', '2026-11-25T10:30:00+08:00')])]);
        $this->artisan('integration:sync-masterdata', ['--only' => ['employees']])->assertSuccessful();

        $this->artisan('integration:sync-masterdata', ['--only' => ['employees']])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'updated_since=2026-11-25T10:30:00+08:00'));
    }

    public function test_full_option_ignores_updated_since(): void
    {
        DB::table('employees')->update(['source_updated_at' => '2026-11-25 10:00:00']);
        $this->fakeOwners();

        $this->artisan('integration:sync-masterdata', ['--only' => ['employees'], '--full' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'updated_since'));
    }

    public function test_one_system_down_does_not_stop_the_other(): void
    {
        $this->fakeOwners([
            '/products' => fn () => throw new ConnectionException('Connection refused'),
            '/warehouses' => fn () => throw new ConnectionException('Connection refused'),
            '/employees' => self::page([self::employee('EMP-0010')]),
        ]);

        $this->artisan('integration:sync-masterdata')->assertFailed();     // may nag-fail, kaya exit 1

        $this->assertDatabaseHas('employees', ['employee_no' => 'EMP-0010']); // pero natuloy ang HR
        $this->assertDatabaseHas('integration_logs', ['direction' => 'out', 'system' => 'INV', 'status_code' => null]);
    }

    public function test_existing_copy_is_updated_not_duplicated(): void
    {
        $this->fakeOwners(['/warehouses' => self::page([[
            'warehouse_code' => 'WH-002', 'name' => 'QC Stockroom (bago)', 'branch_code' => 'BR-002',
            'is_default' => true, 'status' => 'Active', 'updated_at' => '2026-11-25T09:00:00+08:00',
        ]])]);

        $this->artisan('integration:sync-masterdata', ['--only' => ['warehouses']])->assertSuccessful();

        $this->assertSame(3, DB::table('warehouses')->count());
        $this->assertDatabaseHas('warehouses', ['warehouse_code' => 'WH-002', 'name' => 'QC Stockroom (bago)']);
    }

    public function test_is_scheduled_every_15_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('*/15')->assertSuccessful();
    }
}
