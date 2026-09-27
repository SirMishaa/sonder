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
            $table->renameColumn('last_synced_at', 'last_checked_at');
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->nullable()->after('author');
            $table->timestamp('last_changed_at')->nullable()->after('last_checked_at');
            $table->timestamp('removed_at')->nullable()->after('last_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn(['fingerprint', 'last_changed_at', 'removed_at']);
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->renameColumn('last_checked_at', 'last_synced_at');
        });
    }
};
