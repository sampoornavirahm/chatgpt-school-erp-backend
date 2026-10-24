<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_school_calendars', function (Blueprint $table): void {
            $table->foreignUuid('school_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->timestamp('projected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_school_calendars');
    }
};
