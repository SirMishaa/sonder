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

    /**
     * A signed-in library always lists at least "Liked Music", so an empty
     * one means YouTube Music served the session as signed out.
     */
    public static function signedOut(): self
    {
        return new self('YouTube Music no longer recognises this cookie as signed in. Paste a fresh cookie to sync again.');
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
