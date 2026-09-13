<?php

declare(strict_types=1);

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'mercure' => [
            'driver' => 'mercure',
            'secret' => env('MERCURE_JWT_SECRET'),
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
