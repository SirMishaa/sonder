<?php

declare(strict_types=1);

/*
 * Laravel-Lang is a dev dependency (it only publishes the lang files), so
 * this file must not reference any of its classes: production installs
 * without it and still loads every config file. The package merges its own
 * defaults under these overrides.
 *
 * @see https://laravel-lang.com/configuration.html
 */

return [
    /*
     * Sonder names its locales language_REGION (see App\Enums\Locale).
     */
    'aliases' => [
        'fr' => 'fr_BE',
        'en' => 'en_US',
    ],
];
