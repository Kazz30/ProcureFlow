<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B7 – integration_logs
 * Talaan ng bawat tawag sa pagitan ng mga system:
 *   direction = in  → may tumawag sa atin (hal. INV → POST /purchase-requests)
 *   direction = out → tayo ang tumawag (hal. PROC → INV POST /stock-movements)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);                 // in / out
            $table->string('system', 10);                   // CRM / PROC / INV / HR (ang kabilang system)
            $table->string('method', 10);                   // GET, POST, ...
            $table->string('url', 500);
            $table->string('reference_number')->nullable(); // hal. GRN-20261123-0001
            $table->json('request_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['system', 'created_at']);
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
