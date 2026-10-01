<?php

declare(strict_types=1);

use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\YouTubeMusic\YouTubeMusicErrorTranslator;
use Ytmusicapi\YTMusic;
use Ytmusicapi\YTMusicServerError;
use Ytmusicapi\YTMusicUserError;

// ytmusicapi declares its exceptions in a file YTMusic.php includes; they
// are not autoloadable on their own.
class_exists(YTMusic::class);

/**
 * @param  class-string<CredentialsRejected|ProviderUnavailable>  $expected
 */
it('classifies ytmusicapi failures', function (Throwable $failure, string $expected): void {
    $translated = (new YouTubeMusicErrorTranslator())->translate($failure);

    expect($translated::class)->toBe($expected)
        ->and($translated->getPrevious())->toBe($failure);
})->with([
    'signed-out account menu' => [new Exception('Could not find account information.'), CredentialsRejected::class],
    'HTTP 401' => [new YTMusicServerError("Server returned HTTP 401: Unauthorized.\n"), CredentialsRejected::class],
    'HTTP 403' => [new YTMusicServerError("Server returned HTTP 403: Forbidden.\n"), CredentialsRejected::class],
    'unusable auth' => [new YTMusicUserError('Please provide authentication before using this function'), CredentialsRejected::class],
    'HTTP 503' => [new YTMusicServerError("Server returned HTTP 503: Unavailable.\n"), ProviderUnavailable::class],
    'transport' => [new WpOrg\Requests\Exception('cURL error 28: timed out', 'curlerror'), ProviderUnavailable::class],
    'anything else' => [new RuntimeException('Undefined property'), ProviderUnavailable::class],
]);
