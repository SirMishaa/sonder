<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provider failures, normalised across providers. Stored as is (for example
 * on a failed sync) and translated only when displayed, so the message
 * follows the viewer's locale rather than the queue worker's.
 */
enum ProviderErrorCode: string
{
    case CredentialsRejected = 'credentials_rejected';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';

    /**
     * @param  array<string, int|string>  $context  Placeholders of the message (`seconds` for a rate limit).
     */
    public function userMessage(Provider $provider, array $context = []): string
    {
        $replace = ['provider' => $provider->label(), ...array_map(strval(...), $context)];

        return match ($this) {
            self::CredentialsRejected => __(':provider refused these credentials. Make sure you are signed in, then connect the account again.', $replace),
            self::Unavailable => __(':provider could not be reached. Try again in a few minutes.', $replace),
            self::RateLimited => __('Sonder is pacing its calls to :provider. Try again in :seconds seconds.', $replace),
        };
    }
}
