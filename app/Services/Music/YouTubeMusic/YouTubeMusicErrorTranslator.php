<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\Provider;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderException;
use App\Exceptions\Providers\ProviderUnavailable;
use Illuminate\Support\Str;
use Throwable;
use Ytmusicapi\YTMusicServerError;
use Ytmusicapi\YTMusicUserError;

/**
 * Sorts ytmusicapi's failures into Sonder's normalised ones. The package
 * throws generic exceptions, so the split relies on what each one says:
 * only a refused session may mark a cookie expired, everything else is
 * treated as a transient outage.
 */
final readonly class YouTubeMusicErrorTranslator
{
    public function translate(Throwable $failure): ProviderException
    {
        if ($failure instanceof ProviderException) {
            return $failure;
        }

        if ($this->isRefusedSession($failure)) {
            return new CredentialsRejected(Provider::YouTubeMusic, $failure->getMessage(), $failure);
        }

        return new ProviderUnavailable(Provider::YouTubeMusic, $failure->getMessage(), $failure);
    }

    /**
     * A signed-out response (the account menu has no account header), an
     * HTTP 401/403, or credentials the package itself cannot use.
     */
    private function isRefusedSession(Throwable $failure): bool
    {
        return $failure instanceof YTMusicUserError
            || ($failure instanceof YTMusicServerError && Str::contains($failure->getMessage(), ['HTTP 401', 'HTTP 403']))
            || $failure->getMessage() === 'Could not find account information.';
    }
}
