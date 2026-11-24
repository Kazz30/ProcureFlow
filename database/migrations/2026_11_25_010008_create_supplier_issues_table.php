<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – supplier_issues (mga reklamo galing CRM, F7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number', 30)->unique();     // SI-20261123-0001
            $table->string('source_system', 10);              // CRM
            $table->string('reference_number', 50);           // FB-20261123-0012
            $table->string('sku', 20)->index();
            $table->string('supplier_code', 20)->nullable()->index(); // null kung walang preferred supplier
            $table->string('issue_type', 30);                 // Defective / Expired / Wrong Item / Damaged Packaging / Other
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity_affected')->default(0);
            $table->string('branch_code', 20)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('status', 20)->default('Open');    // Open / In Review / Resolved / Closed
            $table->text('resolution')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'reference_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_issues');
    }
};
