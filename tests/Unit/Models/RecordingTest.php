<?php

declare(strict_types=1);

use App\Models\Recording;

it('keeps its match key in step with its title and artist', function (): void {
    $recording = Recording::factory()->create(['title' => 'Survival (Official Video)', 'artist_name' => 'The Muse']);

    expect($recording->match_title)->toBe('survival')
        ->and($recording->match_artist)->toBe('muse');

    $recording->update(['title' => 'Uprising']);

    expect($recording->fresh()?->match_title)->toBe('uprising');
});

it('exists without any registry identifier and is then found by name', function (): void {
    $nameOnly = Recording::factory()->create(['mbid' => null, 'isrc' => null, 'title' => 'Still Here', 'artist_name' => 'League of Legends']);
    Recording::factory()->create(['title' => 'Still Here', 'artist_name' => 'League of Legends']);

    expect($nameOnly->isNameOnly())->toBeTrue()
        ->and(Recording::findByName('still here!', 'league of legends')?->id)->toBe($nameOnly->id)
        ->and(Recording::findByName('Other', 'League of Legends'))->toBeNull();
});

it('lets two recordings share an isrc', function (): void {
    Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    Recording::factory()->create(['isrc' => 'GBAHT1200434']);

    expect(Recording::query()->where('isrc', 'GBAHT1200434')->count())->toBe(2);
});
