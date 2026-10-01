<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderException;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Exceptions\Providers\ProviderUnavailable;
use Illuminate\Support\Facades\Exceptions;

it('describes each failure with a code and a translated message naming the provider', function (ProviderException $exception, ProviderErrorCode $code, string $message): void {
    expect($exception->errorCode())->toBe($code)
        ->and($exception->provider())->toBe(Provider::YouTubeMusic)
        ->and($exception->userMessage())->toBe($message);
})->with([
    'credentials' => [
        fn () => new CredentialsRejected(Provider::YouTubeMusic, 'signed out'),
        ProviderErrorCode::CredentialsRejected,
        'YouTube Music refused these credentials. Make sure you are signed in, then connect the account again.',
    ],
    'unavailable' => [
        fn () => new ProviderUnavailable(Provider::YouTubeMusic, 'timeout'),
        ProviderErrorCode::Unavailable,
        'YouTube Music could not be reached. Try again in a few minutes.',
    ],
    'rate limited' => [
        fn () => new ProviderRateLimited(Provider::YouTubeMusic, 42),
        ProviderErrorCode::RateLimited,
        'Sonder is pacing its calls to YouTube Music. Try again in 42 seconds.',
    ],
]);

it('keeps the technical reason as the exception message', function (): void {
    $exception = new ProviderUnavailable(Provider::YouTubeMusic, 'HTTP 503 from youtubei/v1/browse');

    expect($exception->getMessage())->toBe('YouTube Music unavailable: HTTP 503 from youtubei/v1/browse');
});

it('translates the user message in the current locale', function (): void {
    app()->setLocale('fr_BE');

    expect((new ProviderRateLimited(Provider::YouTubeMusic, 42))->userMessage())
        ->toBe('Sonder espace ses appels à YouTube Music. Réessayez dans 42 secondes.');
});

it('never reports a rate limit', function (): void {
    Exceptions::fake();

    report(new ProviderRateLimited(Provider::YouTubeMusic, 42));
    report(new ProviderUnavailable(Provider::YouTubeMusic, 'timeout'));

    Exceptions::assertNotReported(ProviderRateLimited::class);
    Exceptions::assertReported(ProviderUnavailable::class);
});
