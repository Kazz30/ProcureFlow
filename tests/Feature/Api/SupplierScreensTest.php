<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Login, suppliers at supplier products (Hakbang 7a).
 */
class SupplierScreensTest extends TestCase
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

    public function test_login_returns_token_and_role(): void
    {
        $this->postJson('/api/login', ['email' => 'grace.ramos@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'Purchasing Officer')
            ->assertJsonStructure(['token']);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $this->postJson('/api/login', ['email' => 'grace.ramos@example.com', 'password' => 'mali'])
            ->assertStatus(422);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/suppliers')->assertStatus(401);
    }

    public function test_guest_without_accept_header_still_gets_401_json(): void
    {
        // Dati: 500 "Route [login] not defined" kapag walang Accept: application/json.
        $this->get('/api/suppliers')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_list_suppliers(): void
    {
        $this->as('EMP-0002')->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.supplier_code', 'SUP-0001');
    }

    public function test_create_supplier_gets_next_code(): void
    {
        $this->as('EMP-0008')->postJson('/api/suppliers', [
            'name' => 'Bagong Supplier', 'status' => 'Active',
        ])->assertCreated()->assertJsonPath('data.supplier_code', 'SUP-0006');
    }

    public function test_manager_cannot_edit_suppliers(): void
    {
        $this->as('EMP-0002')->postJson('/api/suppliers', ['name' => 'X', 'status' => 'Active'])
            ->assertForbidden();
    }

    public function test_setting_new_preferred_supplier_unsets_the_old_one(): void
    {
        $this->as('EMP-0008')->postJson('/api/supplier-products', [
            'supplier_code' => 'SUP-0002', 'sku' => 'SKU-0001',
            'unit_cost' => 265, 'lead_time_days' => 5, 'is_preferred' => true,
        ])->assertCreated();

        $preferred = DB::table('supplier_products')->where('sku', 'SKU-0001')->where('is_preferred', true)->pluck('supplier_code');
        $this->assertSame(['SUP-0002'], $preferred->all());
    }

    public function test_supplier_product_requires_known_sku(): void
    {
        $this->as('EMP-0008')->postJson('/api/supplier-products', [
            'supplier_code' => 'SUP-0001', 'sku' => 'SKU-9999',
            'unit_cost' => 10, 'lead_time_days' => 1, 'is_preferred' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('sku');
    }
}
