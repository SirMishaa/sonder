<?php

declare(strict_types=1);

use App\Support\ReleaseDate;

it('pins partial release dates to their first day', function (mixed $raw, ?string $date): void {
    expect(ReleaseDate::normalize($raw))->toBe($date);
})->with([
    ['2012-06-27', '2012-06-27'],
    ['2012-06', '2012-06-01'],
    ['2012', '2012-01-01'],
    ['', null],
    [null, null],
    ['June 2012', null],
]);
