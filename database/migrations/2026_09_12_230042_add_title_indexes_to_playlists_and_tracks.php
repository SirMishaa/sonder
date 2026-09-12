<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->index('title');
        });

        Schema::table('tracks', function (Blueprint $table): void {
            $table->index('title');
            $table->index('artists');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropIndex(['title']);
        });

        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex(['title']);
            $table->dropIndex(['artists']);
        });
    }
};
