<?php

declare(strict_types=1);

use App\Enums\CreditType;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\Credit;

it('reads the identity of an isrc detail', function (): void {
    $detail = CreditsFmMapper::detail(metadataFixture('credits-fm-isrc'));

    expect($detail->isrc)->toBe('GBAHT1200434')
        ->and($detail->title)->toBe('Survival')
        ->and($detail->artists)->toBe(['Muse'])
        ->and($detail->iswc)->toBe('T-912674410-3')
        ->and($detail->releaseDate)->toBe('2012-01-01');
});

it('flattens songwriters, publishers, performers and artists into credits without repeats', function (): void {
    $credits = CreditsFmMapper::credits(metadataFixture('credits-fm-isrc'));
    $summary = array_map(fn (Credit $credit): string => "{$credit->type->value}:{$credit->name}:{$credit->role}", $credits);

    expect($summary)->toContain('artist:Muse:')
        ->toContain('songwriter:MATTHEW JAMES BELLAMY:ComposerLyricist')
        ->toContain('publisher:HEWRATE LIMITED:OriginalPublisher')
        ->toContain('producer:Chris Lord‐Alge:mix')
        ->toContain('performer:Matt Bellamy:vocal')
        ->and($summary)->toBe(array_values(array_unique($summary)));

    $publisher = collect($credits)->first(fn (Credit $credit): bool => $credit->name === 'HEWRATE LIMITED');
    $vocal = collect($credits)->first(fn (Credit $credit): bool => $credit->name === 'Matt Bellamy');

    expect($publisher?->ipi)->toBe('00475448521')
        ->and($vocal?->type)->toBe(CreditType::Performer)
        ->and($vocal?->mbid)->toBe('00fc124e-6645-4530-8d0b-7def83c5ee25')
        ->and($vocal?->attributes)->toBe(['lead vocals']);
});
