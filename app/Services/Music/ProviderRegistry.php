<?php

declare(strict_types=1);

namespace App\Services\Music;

use App\Enums\Provider;
use App\Exceptions\Providers\UnsupportedCapability;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Knows, for each provider, which adapter and credentials classes implement
 * it. Holds class names only and resolves adapters through the container on
 * every call, so it is safe as a singleton under Octane.
 */
final class ProviderRegistry
{
    /** @var array<string, array{adapter: class-string<ProviderAdapter>, credentials: class-string<ProviderCredentials>}> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ProviderAdapter>  $adapter
     * @param  class-string<ProviderCredentials>  $credentials
     */
    public function register(Provider $provider, string $adapter, string $credentials): self
    {
        $this->providers[$provider->value] = ['adapter' => $adapter, 'credentials' => $credentials];

        return $this;
    }

    public function adapter(Provider $provider): ProviderAdapter
    {
        return $this->container->make($this->entry($provider)['adapter']);
    }

    /**
     * The adapter as the given capability, or null when the provider lacks it.
     *
     * @template TCapability of object
     *
     * @param  class-string<TCapability>  $capability
     * @return TCapability|null
     */
    public function capability(Provider $provider, string $capability): ?object
    {
        $adapter = $this->adapter($provider);

        return $adapter instanceof $capability ? $adapter : null;
    }

    /**
     * The adapter as the given capability, for code that cannot work without it.
     *
     * @template TCapability of object
     *
     * @param  class-string<TCapability>  $capability
     * @return TCapability
     *
     * @throws UnsupportedCapability
     */
    public function require(Provider $provider, string $capability): object
    {
        return $this->capability($provider, $capability)
            ?? throw new UnsupportedCapability($provider, $capability);
    }

    /**
     * Rebuilds a provider's typed credentials from their stored array form.
     *
     * @param  array<string, string>  $values
     */
    public function credentials(Provider $provider, array $values): ProviderCredentials
    {
        return $this->entry($provider)['credentials']::fromArray($values);
    }

    /**
     * @return array{adapter: class-string<ProviderAdapter>, credentials: class-string<ProviderCredentials>}
     */
    private function entry(Provider $provider): array
    {
        return $this->providers[$provider->value]
            ?? throw new LogicException("No adapter is registered for {$provider->label()}.");
    }
}
