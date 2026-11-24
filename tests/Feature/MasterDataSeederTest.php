<?php

namespace Tests\Feature;

use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MasterDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_loads_all_csv_rows(): void
    {
        $this->seed(MasterDataSeeder::class);

        $this->assertSame(3, DB::table('branches')->count());
        $this->assertSame(7, DB::table('departments')->count());
        $this->assertSame(9, DB::table('employees')->count());
        $this->assertSame(15, DB::table('products')->count());
        $this->assertSame(3, DB::table('warehouses')->count());
        $this->assertSame(5, DB::table('suppliers')->count());
        $this->assertSame(15, DB::table('supplier_products')->count());
    }

    public function test_seeder_creates_the_three_users_with_roles(): void
    {
        $this->seed(MasterDataSeeder::class);

        $this->assertDatabaseHas('users', ['employee_no' => 'EMP-0001', 'role' => 'Admin', 'can_login' => true]);
        $this->assertDatabaseHas('users', ['employee_no' => 'EMP-0002', 'role' => 'Manager']);
        $this->assertDatabaseHas('users', ['employee_no' => 'EMP-0008', 'role' => 'Purchasing Officer', 'email' => 'grace.ramos@example.com']);
        $this->assertSame(3, DB::table('users')->count());
    }

    public function test_seeder_can_run_twice_without_duplicates_and_keeps_passwords(): void
    {
        $this->seed(MasterDataSeeder::class);
        DB::table('users')->where('employee_no', 'EMP-0001')->update(['password' => Hash::make('bago-na')]);

        $this->seed(MasterDataSeeder::class);

        $this->assertSame(9, DB::table('employees')->count());
        $this->assertSame(15, DB::table('supplier_products')->count());
        $this->assertSame(3, DB::table('users')->count());
        $this->assertTrue(Hash::check('bago-na', DB::table('users')->where('employee_no', 'EMP-0001')->value('password')));
    }

    public function test_only_one_preferred_supplier_per_sku(): void
    {
        $this->seed(MasterDataSeeder::class);

        // SUP-0001 na ang preferred ng SKU-0001. Hindi puwedeng maging preferred din ang SUP-0002.
        $this->expectException(QueryException::class);

        DB::table('supplier_products')->insert([
            'supplier_code' => 'SUP-0002', 'sku' => 'SKU-0001', 'unit_cost' => 265,
            'lead_time_days' => 5, 'is_preferred' => true,
        ]);
    }

    public function test_non_preferred_suppliers_for_same_sku_are_allowed(): void
    {
        $this->seed(MasterDataSeeder::class);

        DB::table('supplier_products')->insert([
            ['supplier_code' => 'SUP-0002', 'sku' => 'SKU-0001', 'unit_cost' => 265, 'lead_time_days' => 5, 'is_preferred' => false],
            ['supplier_code' => 'SUP-0003', 'sku' => 'SKU-0001', 'unit_cost' => 268, 'lead_time_days' => 2, 'is_preferred' => false],
        ]);

        $this->assertSame(3, DB::table('supplier_products')->where('sku', 'SKU-0001')->count());
    }
}
