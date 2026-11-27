<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'student_qr_expires_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('student_qr_expires_at')->nullable();
            });
        }

        DB::table('users')->whereNotNull('student_qr_token_hash')
            ->whereNull('student_qr_expires_at')
            ->update(['student_qr_expires_at' => now()->addYear()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'student_qr_expires_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('student_qr_expires_at');
            });
        }
    }
};
