<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relay_parent_links', function (Blueprint $table): void {
            $table->string('access_level', 24)->default('full');
            $table->index(['school_id', 'user_id', 'access_level'], 'relay_parent_link_access_index');
        });
    }

    public function down(): void
    {
        Schema::table('relay_parent_links', function (Blueprint $table): void {
            $table->dropIndex('relay_parent_link_access_index');
            $table->dropColumn('access_level');
        });
    }
};
