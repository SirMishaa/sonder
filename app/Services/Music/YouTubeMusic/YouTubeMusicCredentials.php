<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderCredentials;
use SensitiveParameter;

/**
 * The browser `cookie` header of a signed-in YouTube Music session. OAuth is
 * refused by YouTube Music's API for this use, so the cookie is the only
 * credential that works.
 */
final readonly class YouTubeMusicCredentials implements ProviderCredentials
{
    public function __construct(#[SensitiveParameter] public string $cookie) {}

    /**
     * Keeps the cookie out of dumps and logs.
     *
     * @return array{cookie: string}
     */
    public function __debugInfo(): array
    {
        return ['cookie' => '[redacted]'];
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function fromArray(array $values): static
    {
        return new self($values['cookie'] ?? '');
    }

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    /**
     * @return array{cookie: string}
     */
    public function toArray(): array
    {
        return ['cookie' => $this->cookie];
    }
}
