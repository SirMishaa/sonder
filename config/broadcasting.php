<?php

declare(strict_types=1);

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'mercure' => [
            'driver' => 'mercure',
            'url' => env('MERCURE_URL'),
            'secret' => env('MERCURE_JWT_SECRET'),
            'cookie_name' => env('MERCURE_COOKIE_NAME'),
            'subscribe_expiration' => 15,
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
