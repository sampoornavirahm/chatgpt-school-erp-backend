<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index(
                ['email', 'phone', 'role', 'google_subject', 'is_active'],
                'users_parent_family_lookup_index',
            );
        });

        Schema::table('relay_parent_links', function (Blueprint $table): void {
            $table->index(['user_id', 'student_public_id'], 'relay_parent_links_user_student_index');
        });
    }

    public function down(): void
    {
        Schema::table('relay_parent_links', function (Blueprint $table): void {
            $table->dropIndex('relay_parent_links_user_student_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_parent_family_lookup_index');
        });
    }
};
