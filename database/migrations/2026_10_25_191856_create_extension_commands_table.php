<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('kind');
            $table->string('list_id', 64)->nullable();
            $table->json('video_ids');
            $table->timestamps();

            $table->index(['user_id', 'list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_commands');
    }
};
