<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 – Mga dagdag sa users.
 *   employee_no:       link sa kopya ng employees (galing HR)
 *   employment_status: kinokopya mula sa employees kapag nag-sync
 *   can_login:         false → hindi makaka-login (F1)
 *   role:              Admin / Manager / Purchasing Officer (A1; sariling PROC lang)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_no', 20)->nullable()->unique()->after('id');
            $table->string('employment_status', 20)->nullable()->after('email');
            $table->boolean('can_login')->default(true)->after('employment_status');
            $table->string('role', 30)->nullable()->after('can_login');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_no']);
            $table->dropColumn(['employee_no', 'employment_status', 'can_login', 'role']);
        });
    }
};
