<?php

declare(strict_types=1);

use App\Services\YouTubeMusic\CookielessSession;
use WpOrg\Requests\Transport;

it('never replays cookies a response set on a later request', function (): void {
    $transport = new class implements Transport
    {
        /** @var array<int, array<string, string>> */
        public array $sentHeaders = [];

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
            $this->sentHeaders[] = $headers;

            return "HTTP/1.1 200 OK\r\nSet-Cookie: __Secure-BUCKET=anonymous; Path=/; Secure\r\nContent-Length: 2\r\n\r\n{}";
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

    $session = CookielessSession::create();
    $session->options['transport'] = $transport;

    $session->get('https://music.youtube.com');
    $session->post('https://music.youtube.com/youtubei/v1/account/account_menu', ['cookie' => 'SID=signed-in'], '{}');

    expect($transport->sentHeaders[1])
        ->toBe(['cookie' => 'SID=signed-in']);
});
