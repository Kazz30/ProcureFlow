<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilang para sa mga numero na PREFIX-YYYYMMDD-0001 (A3).
 * Isang row bawat prefix bawat araw; ang last_number ay ang huling nagamit.
 * Nilo-lock ang row habang kumukuha ng bagong numero, kaya walang doble.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);          // PR, PO, GRN, SR, SI
            $table->date('sequence_date');         // ang araw (Asia/Manila)
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'sequence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
