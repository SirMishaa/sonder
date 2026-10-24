<?php

declare(strict_types=1);

use App\Services\Music\YouTubeMusic\SessionObserver;
use WpOrg\Requests\Response;

/**
 * @param  list<string>  $setCookies
 */
function aYouTubeMusicResponse(string $body, array $setCookies = [], int $status = 200): Response
{
    $response = new Response();
    $response->status_code = $status;
    $response->body = $body;

    foreach ($setCookies as $cookie) {
        $response->headers['set-cookie'] = $cookie;
    }

    return $response;
}

function aTrackedBody(string $loggedIn): string
{
    return json_encode(['responseContext' => ['serviceTrackingParams' => [
        ['service' => 'CSI', 'params' => [['key' => 'c', 'value' => 'WEB_REMIX']]],
        ['service' => 'GFEEDBACK', 'params' => [['key' => 'logged_in', 'value' => $loggedIn]]],
    ]]], JSON_THROW_ON_ERROR);
}

it('reads whether YouTube Music served the session signed in', function (string $flag, bool $expected): void {
    $observer = new SessionObserver();

    $observer->observe(aYouTubeMusicResponse(aTrackedBody($flag)));

    expect($observer->loggedIn())->toBe($expected);
})->with([
    'signed in' => ['1', true],
    'signed out' => ['0', false],
]);

it('keeps the last known state when a response does not report it', function (): void {
    $observer = new SessionObserver();

    $observer->observe(aYouTubeMusicResponse(aTrackedBody('0')));
    $observer->observe(aYouTubeMusicResponse('not json', status: 500));

    expect($observer->loggedIn())->toBeFalse()
        ->and($observer->requests())->toBe(2)
        ->and($observer->status())->toBe(500);
});

it('knows nothing of the session before any response says so', function (): void {
    expect((new SessionObserver())->loggedIn())->toBeNull();
});

it('records the names of rotated cookies, never their values', function (): void {
    $observer = new SessionObserver();

    $observer->observe(aYouTubeMusicResponse('{}', [
        '__Secure-3PSIDCC=secret-value; Path=/; Secure',
        'YSC=another-secret; Path=/',
    ]));
    $observer->observe(aYouTubeMusicResponse('{}', ['YSC=again; Path=/']));

    expect($observer->cookiesSet())->toBe(['YSC', '__Secure-3PSIDCC'])
        ->and(implode(',', $observer->cookiesSet()))->not->toContain('secret');
});

it('observes every response of a session it is attached to', function (): void {
    $transport = new class implements WpOrg\Requests\Transport
    {
        public static function test($capabilities = []): bool
        {
            return true;
        }

        /**
         * @param  array<string, string>  $headers
         * @param  array<string, mixed>|string  $data
         * @param  array<string, mixed>  $options
         */
        public function request($url, $headers = [], $data = [], $options = []): string
        {
            $body = aTrackedBody('1');

            return "HTTP/1.1 200 OK\r\nSet-Cookie: __Secure-1PSIDTS=rotated; Path=/; Secure\r\nContent-Length: ".mb_strlen($body, '8bit')."\r\n\r\n{$body}";
        }

        /**
         * @param  array<int, mixed>  $requests
         * @param  array<string, mixed>  $options
         * @return array<int, string>
         */
        public function request_multiple($requests, $options): array
        {
            return [];
        }
    };
    $observer = new SessionObserver();
    $session = $observer->attach(App\Services\Music\YouTubeMusic\CookielessSession::create());
    $session->options['transport'] = $transport;

    $session->post('https://music.youtube.com/youtubei/v1/browse', ['cookie' => 'SID=signed-in'], '{}');

    expect($observer->requests())->toBe(1)
        ->and($observer->loggedIn())->toBeTrue()
        ->and($observer->cookiesSet())->toBe(['__Secure-1PSIDTS']);
});
