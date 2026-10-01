<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemotePlaylistSummary;

function summary(?int $trackCount = 12, string $title = 'Deep Focus'): RemotePlaylistSummary
{
    return new RemotePlaylistSummary(new ProviderRef(Provider::YouTubeMusic, 'PL1'), $title, 'No lyrics', $trackCount, 'https://img.test/a.jpg', 'Mishaa');
}

it('keeps the same fingerprint while the listing is unchanged', function (): void {
    expect(summary()->fingerprint())->toBe(summary()->fingerprint());
});

it('changes the fingerprint when any listed detail changes', function (): void {
    expect(summary(trackCount: 13)->fingerprint())->not->toBe(summary()->fingerprint())
        ->and(summary(title: 'Late focus')->fingerprint())->not->toBe(summary()->fingerprint());
});
