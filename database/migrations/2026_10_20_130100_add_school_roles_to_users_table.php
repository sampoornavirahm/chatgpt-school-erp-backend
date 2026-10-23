<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('school_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('school_admin')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->dropUnique(['email']);
            $table->unique(['school_id', 'email']);
            $table->index(['school_id', 'role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['school_id', 'role', 'is_active']);
            $table->dropUnique(['school_id', 'email']);
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn(['role', 'is_active']);
            $table->unique('email');
        });
    }
};
