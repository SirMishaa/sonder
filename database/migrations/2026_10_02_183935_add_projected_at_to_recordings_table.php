<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->timestamp('projected_at')->nullable()->index();
        });

        // Recordings already projected keep their last change as a best guess.
        DB::table('recordings')
            ->where(fn (Builder $query): Builder => $query
                ->whereExists(fn (Builder $credits): Builder => $credits->select(DB::raw(1))->from('recording_contributors')->whereColumn('recording_contributors.recording_id', 'recordings.id'))
                ->orWhereExists(fn (Builder $tags): Builder => $tags->select(DB::raw(1))->from('recording_tags')->whereColumn('recording_tags.recording_id', 'recordings.id')))
            ->update(['projected_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropColumn('projected_at');
        });
    }
};
