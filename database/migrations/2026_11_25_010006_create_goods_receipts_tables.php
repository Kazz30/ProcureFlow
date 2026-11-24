<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – goods_receipts at goods_receipt_items (pag-receive, F5 → INV PURCHASE_IN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('grn_number', 30)->unique();       // GRN-20261123-0001
            $table->string('po_number', 30)->index();
            $table->string('warehouse_code', 20);
            $table->string('branch_code', 20);
            $table->string('received_by', 20)->nullable();    // employee_no
            $table->timestamp('received_at')->nullable();
            $table->string('stock_sync_status', 20)->default('not_sent'); // not_sent / pending / synced / failed
            $table->string('movement_number', 30)->nullable(); // sagot ng INV (SM-...)
            $table->timestamps();

            $table->foreign('po_number')->references('po_number')->on('purchase_orders')->cascadeOnUpdate();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 20)->index();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
