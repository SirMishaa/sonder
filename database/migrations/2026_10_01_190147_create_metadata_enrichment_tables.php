<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mbid')->nullable()->unique();
            $table->string('isrc', 12)->nullable()->index();
            $table->string('iswc')->nullable();
            $table->string('title');
            $table->string('artist_name');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedBigInteger('lastfm_listeners')->nullable();
            $table->unsignedBigInteger('lastfm_playcount')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE recordings ADD CONSTRAINT recordings_identified CHECK (mbid IS NOT NULL OR isrc IS NOT NULL)');

        Schema::create('recording_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('external_id');
            $table->foreignUuid('recording_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('method')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('query_title');
            $table->string('query_artist');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->uuid('mbid')->nullable()->unique();
            $table->string('ipi', 11)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('recording_contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recording_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contributor_id')->index()->constrained()->cascadeOnDelete();
            $table->string('credit_type');
            $table->string('role')->default('');
            $table->json('credit_attributes');
            $table->string('source');
            $table->timestamps();

            $table->unique(['recording_id', 'contributor_id', 'credit_type', 'role', 'source'], 'recording_contributors_unique');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_genre')->default(false);
            $table->timestamps();
        });

        Schema::create('recording_tags', function (Blueprint $table): void {
            $table->foreignUuid('recording_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->index()->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedTinyInteger('weight');

            $table->primary(['recording_id', 'tag_id', 'source']);
        });

        Schema::create('contributor_tags', function (Blueprint $table): void {
            $table->foreignUuid('contributor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->index()->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedTinyInteger('weight');

            $table->primary(['contributor_id', 'tag_id', 'source']);
        });

        Schema::create('enrichments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->string('subject_key');
            $table->string('source');
            $table->string('endpoint');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_key', 'source', 'endpoint'], 'enrichments_unique');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichments');
        Schema::dropIfExists('contributor_tags');
        Schema::dropIfExists('recording_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('recording_contributors');
        Schema::dropIfExists('contributors');
        Schema::dropIfExists('recording_resolutions');
        Schema::dropIfExists('recordings');
    }
};
