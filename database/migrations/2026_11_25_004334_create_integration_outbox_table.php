<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B7 – integration_outbox
 * Pila ng mga request na ipapadala sa ibang system.
 * Outbox::push() ang naglalagay dito (status = pending); ang ProcessOutbox ang nagpapadala.
 *
 * Retry rule: kapag nag-fail, attempts + 1 at next_attempt_at = 1, 2, 4, 8 minuto.
 * Pagdating ng 5 attempts → failed. Kapag 422 ang sagot → failed agad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('target_system', 10);            // CRM / PROC / INV / HR
            $table->string('method', 10);                   // POST, PUT, ...
            $table->string('endpoint');                     // hal. /stock-movements
            $table->json('payload');
            $table->string('reference_number');             // hal. GRN-20261123-0001
            $table->string('status', 10)->default('pending'); // pending / sent / failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Walang doble: iisang request lang bawat system + endpoint + reference_number.
            // (Maikling pangalan ng index: 64 characters lang ang limit ng MySQL.)
            $table->unique(['target_system', 'endpoint', 'reference_number'], 'outbox_target_endpoint_ref_unique');
            // Para mabilis hanapin ng ProcessOutbox ang mga dapat ipadala.
            $table->index(['status', 'next_attempt_at'], 'outbox_status_next_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
    }
};
