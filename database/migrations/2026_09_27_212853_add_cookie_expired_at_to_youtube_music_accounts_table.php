<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_music_accounts', function (Blueprint $table): void {
            $table->timestamp('cookie_expired_at')->nullable()->after('last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_music_accounts', function (Blueprint $table): void {
            $table->dropColumn('cookie_expired_at');
        });
    }
};
