<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – supplier_returns at supplier_return_items (F5 → INV SUPPLIER_RETURN_OUT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 30)->unique();    // SR-20261123-0001
            $table->string('supplier_code', 20);
            $table->string('grn_number', 30)->nullable()->index();
            $table->string('warehouse_code', 20);
            $table->string('branch_code', 20);
            $table->text('reason')->nullable();
            $table->string('returned_by', 20)->nullable();    // employee_no
            $table->string('status', 20)->default('Pending'); // MUNGKAHI: Pending / Returned / Cancelled
            $table->string('stock_sync_status', 20)->default('not_sent'); // not_sent / pending / synced / failed
            $table->string('movement_number', 30)->nullable();
            $table->timestamps();

            $table->foreign('supplier_code')->references('supplier_code')->on('suppliers')->cascadeOnUpdate();
        });

        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 20)->index();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
    }
};
