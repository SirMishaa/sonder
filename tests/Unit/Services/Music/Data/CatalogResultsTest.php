<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Services\Music\Data\CatalogArtistSummary;
use App\Services\Music\Data\CatalogResults;
use App\Services\Music\Data\ProviderRef;

it('reports empty results', function (): void {
    expect((new CatalogResults(null, [], [], [], []))->isEmpty())->toBeTrue();
});

it('is not empty with a top artist only', function (): void {
    $artist = new CatalogArtistSummary(new ProviderRef(Provider::YouTubeMusic, 'UCs6GGpd9zvsYghuYe0VDFUQ'), 'Rammstein', null, null);

    expect((new CatalogResults($artist, [], [], [], []))->isEmpty())->toBeFalse();
});
