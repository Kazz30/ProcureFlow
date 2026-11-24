<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * B7 – MasterDataSeeder
 * Binabasa ang database/seeders/data/*.csv (galing sa shared Sheet, Bahagi C)
 * at nag-u-UPSERT gamit ang code: kapag mayroon na, ina-update; kapag wala, idinadagdag.
 * Kaya ligtas itong patakbuhin nang paulit-ulit, pati sa live:
 *   php artisan db:seed --class=MasterDataSeeder
 */
class MasterDataSeeder extends Seeder
{
    /** Ang mga user account na gagawin (A3), ayon sa employee_no. */
    private const USERS = [
        'EMP-0001' => 'Admin',
        'EMP-0002' => 'Manager',
        'EMP-0008' => 'Purchasing Officer',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            // Mga kopya galing HR
            $this->upsert('branches.csv', 'branches', 'branch_code', fn ($r) => [
                'branch_code' => $r['branch_code'],
                'name' => $r['name'],
                'city' => $r['city'] ?: null,
                'status' => $r['status'] ?: 'Active',
            ]);

            $this->upsert('departments.csv', 'departments', 'department_code', fn ($r) => [
                'department_code' => $r['department_code'],
                'name' => $r['name'],
                'status' => $r['status'] ?: 'Active',
            ]);

            $this->upsert('employees.csv', 'employees', 'employee_no', fn ($r) => [
                'employee_no' => $r['employee_no'],
                'first_name' => $r['first_name'],
                'last_name' => $r['last_name'],
                'email' => $r['email'] ?: null,
                'branch_code' => $r['branch_code'] ?: null,
                'department_code' => $r['department_code'] ?: null,
                'position' => $r['position'] ?: null,
                'employment_status' => $r['employment_status'],
                // B7: true lang kung Active o On Leave.
                'can_login' => in_array($r['employment_status'], Employee::LOGIN_STATUSES, true),
            ]);

            // Mga kopya galing INV
            $this->upsert('products.csv', 'products', 'sku', fn ($r) => [
                'sku' => $r['sku'],
                'name' => $r['name'],
                'category' => $r['category'] ?: null,
                'unit' => $r['unit'] ?: null,
                'price' => $r['price'],
                'cost' => $r['cost'],
                'reorder_level' => (int) $r['reorder_level'],
                'reorder_quantity' => (int) $r['reorder_quantity'],
                'status' => $r['status'] ?? 'Active', // wala sa CSV → Active
            ]);

            $this->upsert('warehouses.csv', 'warehouses', 'warehouse_code', fn ($r) => [
                'warehouse_code' => $r['warehouse_code'],
                'name' => $r['name'],
                'branch_code' => $r['branch_code'],
                'is_default' => (bool) $r['is_default'],
                'status' => $r['status'] ?: 'Active',
            ]);

            // Sariling data ng PROC
            $this->upsert('suppliers.csv', 'suppliers', 'supplier_code', fn ($r) => [
                'supplier_code' => $r['supplier_code'],
                'name' => $r['name'],
                'contact_person' => $r['contact_person'] ?: null,
                'email' => $r['email'] ?: null,
                'phone' => $r['phone'] ?: null,
                'status' => $r['status'] ?: 'Active',
            ]);

            $this->upsert('supplier_products.csv', 'supplier_products', ['supplier_code', 'sku'], fn ($r) => [
                'supplier_code' => $r['supplier_code'],
                'sku' => $r['sku'],
                'unit_cost' => $r['unit_cost'],
                'lead_time_days' => (int) $r['lead_time_days'],
                'is_preferred' => (bool) $r['is_preferred'],
            ]);

            $this->seedUsers();
        });
    }

    /**
     * Gumagawa ng user account para sa EMP-0001, EMP-0002 at EMP-0008, kapag WALA pa.
     * Kapag mayroon na: HINDI binabago ang password at role (baka binago ng Admin);
     * ang employment_status at can_login lang ang kinokopya mula sa employees (F1).
     */
    private function seedUsers(): void
    {
        // Default na password para sa local. Sa AWS, palitan agad pagkatapos ng unang login.
        // (Puwedeng palitan gamit ang SEED_DEFAULT_PASSWORD sa .env; blangko → "password".)
        $password = env('SEED_DEFAULT_PASSWORD') ?: 'password';

        foreach (self::USERS as $employeeNo => $role) {
            $employee = Employee::where('employee_no', $employeeNo)->first();
            if (! $employee) {
                throw new RuntimeException("Walang $employeeNo sa employees.csv");
            }

            $user = User::firstOrCreate(['employee_no' => $employeeNo], [
                'name' => $employee->full_name,
                'email' => $employee->email,
                'password' => Hash::make($password),
                'role' => $role,
                'employment_status' => $employee->employment_status,
                'can_login' => $employee->can_login,
            ]);

            $this->command?->info("  user {$employeeNo} ({$user->role}): {$user->email}".($user->wasRecentlyCreated ? ' (bago)' : ''));
        }

        // F1: lahat ng user na may employee_no → kopyahin ang status; burahin ang tokens kapag hindi na puwede.
        $deactivated = User::syncLoginFromEmployees();
        if ($deactivated > 0) {
            $this->command?->warn("  $deactivated user ang hindi na makaka-login (binura ang tokens).");
        }
    }

    /**
     * Binabasa ang isang CSV at nag-u-upsert sa table gamit ang code.
     */
    private function upsert(string $file, string $table, string|array $uniqueBy, callable $map): void
    {
        $rows = array_map($map, $this->readCsv($file));
        $now = now();

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        // Ina-update ang lahat ng column maliban sa code at created_at.
        $update = array_values(array_diff(array_keys($rows[0]), (array) $uniqueBy, ['created_at']));

        DB::table($table)->upsert($rows, (array) $uniqueBy, $update);

        $this->command?->info(sprintf('  %-18s %d rows', $table, count($rows)));
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $file): array
    {
        $path = database_path("seeders/data/$file");
        if (! is_file($path)) {
            throw new RuntimeException("Walang CSV: $path");
        }

        $handle = fopen($path, 'r');
        $header = array_map('trim', fgetcsv($handle, escape: ''));
        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null] || $line === ['']) {
                continue; // blangkong linya
            }
            $rows[] = array_combine($header, array_map('trim', $line));
        }

        fclose($handle);

        return $rows;
    }
}
