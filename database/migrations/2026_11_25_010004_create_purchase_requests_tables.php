<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – purchase_requests at purchase_request_items.
 * Galing INV (low-stock, F4) o ginawa nang manual sa PROC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number', 30)->unique();        // PR-20261123-0001
            $table->string('source_system', 10);              // INV o PROC
            $table->string('reference_number', 50);           // hal. LSA-20261123-0004, o ang sariling pr_number
            $table->string('branch_code', 20)->index();
            $table->string('requested_by', 20)->nullable();   // employee_no
            $table->date('needed_by')->nullable();
            $table->string('status', 20)->default('Pending'); // Pending / Approved / Rejected / Converted
            $table->string('approved_by', 20)->nullable();    // employee_no ng Manager
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            // B5: walang doble kapag inulit ang parehong POST.
            $table->unique(['source_system', 'reference_number']);
            $table->index('status');
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 20)->index();
            $table->unsignedInteger('quantity');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
