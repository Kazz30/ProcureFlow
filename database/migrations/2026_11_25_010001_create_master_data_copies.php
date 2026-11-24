<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – Mga KOPYA (read-only sa PROC).
 *   Galing HR:  branches, departments, employees
 *   Galing INV: products, warehouses
 * Ang MasterDataSeeder at integration:sync-masterdata lang ang nagpapalit nito.
 * Ang CODE ang unique (B4), hindi ang id. Walang foreign key papunta sa mga kopya,
 * para hindi masira ang sync kapag nauna dumating ang isang record kaysa sa iba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 20)->unique();     // BR-001
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('status', 20)->default('Active');
            $table->timestamp('source_updated_at')->nullable(); // updated_at ng may-ari (HR)
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('department_code', 20)->unique(); // DEP-001
            $table->string('name');
            $table->string('status', 20)->default('Active');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 20)->unique();     // EMP-0001
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('branch_code', 20)->nullable()->index();
            $table->string('department_code', 20)->nullable();
            $table->string('position')->nullable();
            $table->string('employment_status', 20)->default('Active');
            $table->boolean('can_login')->default(true);     // true lang kung Active o On Leave
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 20)->unique();             // SKU-0001
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->unsignedInteger('reorder_quantity')->default(0);
            $table->string('status', 20)->default('Active');
            $table->timestamp('source_updated_at')->nullable(); // updated_at ng may-ari (INV)
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_code', 20)->unique();  // WH-001
            $table->string('name');
            $table->string('branch_code', 20)->index();
            $table->boolean('is_default')->default(false);   // default warehouse ng branch
            $table->string('status', 20)->default('Active');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
    }
};
