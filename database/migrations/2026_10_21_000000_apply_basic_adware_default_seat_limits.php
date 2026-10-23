<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $starter = DB::table('license_plans')->where('key', 'starter')->first();
        if ($starter === null) {
            return;
        }

        $basic = DB::table('license_plans')->where('key', 'basic_adware');
        $basic->whereNull('student_limit')->update([
            'student_limit' => $starter->student_limit ?? 100,
            'updated_at' => now(),
        ]);

        DB::table('license_plans')->where('key', 'basic_adware')->whereNull('teacher_limit')->update([
            'teacher_limit' => $starter->teacher_limit ?? 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep the safe seat defaults if application code is rolled back.
    }
};
