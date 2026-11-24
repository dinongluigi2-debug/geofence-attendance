<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'student_qr_token_hash')) {
                $table->string('student_qr_token_hash', 64)->nullable()->unique();
            }

            if (! Schema::hasColumn('users', 'student_qr_token_encrypted')) {
                $table->text('student_qr_token_encrypted')->nullable();
            }

            if (! Schema::hasColumn('users', 'student_qr_revoked_at')) {
                $table->timestamp('student_qr_revoked_at')->nullable();
            }
        });

        Schema::table('attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendances', 'attendance_method')) {
                $table->string('attendance_method', 24)->nullable();
            }

            if (! Schema::hasColumn('attendances', 'recorded_by')) {
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('attendances', 'manual_reason')) {
                $table->text('manual_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            if (Schema::hasColumn('attendances', 'recorded_by')) {
                $table->dropConstrainedForeignId('recorded_by');
            }

            foreach (['attendance_method', 'manual_reason'] as $column) {
                if (Schema::hasColumn('attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'student_qr_token_hash')) {
                $table->dropUnique(['student_qr_token_hash']);
                $table->dropColumn('student_qr_token_hash');
            }

            if (Schema::hasColumn('users', 'student_qr_token_encrypted')) {
                $table->dropColumn('student_qr_token_encrypted');
            }

            if (Schema::hasColumn('users', 'student_qr_revoked_at')) {
                $table->dropColumn('student_qr_revoked_at');
            }
        });
    }
};
