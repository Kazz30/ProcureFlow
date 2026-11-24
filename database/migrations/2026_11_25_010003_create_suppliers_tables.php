<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – suppliers at supplier_products (sariling data ng PROC, F6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 20)->unique();   // SUP-0001
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('status', 20)->default('Active'); // Active / Inactive
            $table->timestamps();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 20);
            $table->string('sku', 20)->index();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->boolean('is_preferred')->default(false);

            // "Isa lang ang preferred bawat sku": ang column na ito ay may laman (= sku)
            // kapag is_preferred = true, at NULL kapag hindi. Dahil unique ito, hindi
            // tatanggapin ng database ang dalawang preferred para sa iisang sku.
            // (Puwedeng maraming NULL sa unique index.)
            $table->string('preferred_sku', 20)
                ->nullable()
                ->storedAs('CASE WHEN is_preferred = 1 THEN sku ELSE NULL END')
                ->unique();

            $table->timestamps();

            $table->unique(['supplier_code', 'sku']);
            $table->foreign('supplier_code')->references('supplier_code')->on('suppliers')->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('suppliers');
    }
};
