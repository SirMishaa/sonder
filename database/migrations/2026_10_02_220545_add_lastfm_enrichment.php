<?php

declare(strict_types=1);

use App\Support\MusicText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recordings DROP CONSTRAINT recordings_identified');

        Schema::table('recordings', function (Blueprint $table): void {
            $table->string('match_title')->default('');
            $table->string('match_artist')->default('');
            $table->index(['match_title', 'match_artist']);
        });

        DB::table('recordings')->select(['id', 'title', 'artist_name'])->lazyById()->each(function (object $recording): void {
            DB::table('recordings')->where('id', $recording->id)->update([
                'match_title' => MusicText::matchTitle(is_string($recording->title) ? $recording->title : ''),
                'match_artist' => MusicText::matchArtist(is_string($recording->artist_name) ? $recording->artist_name : ''),
            ]);
        });

        Schema::table('contributors', function (Blueprint $table): void {
            $table->unsignedBigInteger('lastfm_listeners')->nullable();
            $table->unsignedBigInteger('lastfm_playcount')->nullable();
        });

        Schema::create('popularity_samples', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('source');
            $table->unsignedBigInteger('listeners');
            $table->unsignedBigInteger('playcount')->nullable();
            $table->timestamp('measured_at');

            $table->index(['subject_type', 'subject_id', 'measured_at']);
        });

        Schema::create('similar_recordings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recording_id')->index()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('artist_name');
            $table->decimal('match', 7, 6);
            $table->string('source');
            $table->timestamps();
        });

        Schema::create('similar_contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contributor_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('match', 7, 6);
            $table->string('source');
            $table->timestamps();
        });

        Schema::create('chart_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->string('chart');
            $table->date('taken_on');
            $table->timestamps();

            $table->unique(['source', 'chart', 'taken_on']);
        });

        Schema::create('chart_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chart_snapshot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('title');
            $table->string('artist_name');
            $table->unsignedBigInteger('listeners')->nullable();
            $table->unsignedBigInteger('playcount')->nullable();
            $table->foreignUuid('recording_id')->nullable()->index()->constrained()->nullOnDelete();

            $table->unique(['chart_snapshot_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_entries');
        Schema::dropIfExists('chart_snapshots');
        Schema::dropIfExists('similar_contributors');
        Schema::dropIfExists('similar_recordings');
        Schema::dropIfExists('popularity_samples');

        Schema::table('contributors', function (Blueprint $table): void {
            $table->dropColumn(['lastfm_listeners', 'lastfm_playcount']);
        });

        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropIndex(['match_title', 'match_artist']);
            $table->dropColumn(['match_title', 'match_artist']);
        });
    }
};
