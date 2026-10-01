<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Registry release dates come as a year, a month or a day; the database
 * stores a date, so a partial one is pinned to its first day.
 */
final class ReleaseDate
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $value, $parts) !== 1) {
            return null;
        }

        return sprintf('%s-%s-%s', $parts[1], ($parts[2] ?? '') !== '' ? $parts[2] : '01', ($parts[3] ?? '') !== '' ? $parts[3] : '01');
    }
}
