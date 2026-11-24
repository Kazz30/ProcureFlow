<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – purchase_orders at purchase_order_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 30)->unique();        // PO-20261123-0001
            $table->string('pr_number', 30)->nullable()->index(); // galing sa aling PR (kung mayroon)
            $table->string('supplier_code', 20);
            $table->string('branch_code', 20)->index();
            $table->string('warehouse_code', 20);             // saan ide-deliver
            $table->string('status', 30)->default('Draft');   // Draft / Approved / Sent / Partially Received / Received / Cancelled
            $table->string('ordered_by', 20)->nullable();     // employee_no
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->foreign('supplier_code')->references('supplier_code')->on('suppliers')->cascadeOnUpdate();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 20)->index();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->unsignedInteger('received_quantity')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
