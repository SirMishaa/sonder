<?php

declare(strict_types=1);

use App\Models\Recording;
use Illuminate\Database\QueryException;

it('refuses a recording no registry identifies', function (): void {
    expect(fn () => Recording::factory()->create(['mbid' => null, 'isrc' => null]))
        ->toThrow(QueryException::class);
});

it('lets two recordings share an isrc', function (): void {
    Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    Recording::factory()->create(['isrc' => 'GBAHT1200434']);

    expect(Recording::query()->where('isrc', 'GBAHT1200434')->count())->toBe(2);
});
