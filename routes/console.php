<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Once a day is enough to warn before the next sync fails, and rare enough
 * not to keep a scale-to-zero environment awake.
 */
Schedule::command('youtube-music:verify-cookies')->daily();

/*
 * Failed, missing and stale metadata is fetched again once a day, for the
 * same reason: a scale-to-zero environment stays asleep the rest of the time.
 */
Schedule::command('metadata:retry-due')->daily();
