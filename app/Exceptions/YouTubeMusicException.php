<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class YouTubeMusicException extends RuntimeException
{
    public static function unreachable(Throwable $previous): self
    {
        return new self(
            'YouTube Music could not be reached, or it returned something this application could not read. '
            .'This usually means the stored cookie has expired.',
            previous: $previous,
        );
    }

    public static function unexpectedPayload(string $expected): self
    {
        return new self(
            "YouTube Music returned something other than the expected {$expected}. "
            .'The private API it exposes changes without notice, so this most likely means the '
            .'ytmusicapi package needs updating.',
        );
    }
}
