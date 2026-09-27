<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Once a day is enough to warn before the next sync fails, and rare enough
 * not to keep a scale-to-zero environment awake.
 */
Schedule::command('youtube-music:verify-cookies')->daily();
