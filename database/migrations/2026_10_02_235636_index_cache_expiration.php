<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catches the cache tables up with Laravel's current stub: the expired
 * locks and entries the store prunes are found by `expiration`, which
 * also outgrows a 32-bit integer in 2038.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cache', 'cache_locks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->bigInteger('expiration')->change();
                $table->index('expiration');
            });
        }
    }

    public function down(): void
    {
        foreach (['cache', 'cache_locks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['expiration']);
                $table->integer('expiration')->change();
            });
        }
    }
};
