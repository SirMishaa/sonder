<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Exceptions\Providers\UnsupportedCapability;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\ProviderRegistry;

final class AccountOnlyAdapter implements ProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function account(ProviderCredentials $credentials): RemoteAccount
    {
        return new RemoteAccount(new ProviderRef(Provider::YouTubeMusic, 'UC1'), 'Listener');
    }
}

final readonly class TokenCredentials implements ProviderCredentials
{
    public function __construct(public string $token) {}

    public static function fromArray(array $values): static
    {
        return new self($values['token'] ?? '');
    }

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function toArray(): array
    {
        return ['token' => $this->token];
    }
}

function registry(): ProviderRegistry
{
    return (new ProviderRegistry(app()))->register(Provider::YouTubeMusic, AccountOnlyAdapter::class, TokenCredentials::class);
}

it('resolves the adapter registered for a provider', function (): void {
    expect(registry()->adapter(Provider::YouTubeMusic))->toBeInstanceOf(AccountOnlyAdapter::class);
});

it('returns null for a capability the adapter lacks', function (): void {
    expect(registry()->capability(Provider::YouTubeMusic, ReadsPlaylists::class))->toBeNull();
});

it('refuses to hand out a required capability the adapter lacks', function (): void {
    registry()->require(Provider::YouTubeMusic, ReadsPlaylists::class);
})->throws(UnsupportedCapability::class, 'YouTube Music does not support '.ReadsPlaylists::class.'.');

it('builds the provider credentials from stored values', function (): void {
    $credentials = registry()->credentials(Provider::YouTubeMusic, ['token' => 'abc']);

    expect($credentials)->toBeInstanceOf(TokenCredentials::class)
        ->and($credentials->toArray())->toBe(['token' => 'abc']);
});

it('fails loudly for a provider nobody registered', function (): void {
    (new ProviderRegistry(app()))->adapter(Provider::YouTubeMusic);
})->throws(LogicException::class, 'No adapter is registered for YouTube Music.');
