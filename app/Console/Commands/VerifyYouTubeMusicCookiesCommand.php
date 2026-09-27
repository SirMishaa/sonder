<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\VerifyYouTubeMusicCookie;
use App\Models\YouTubeMusicAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('youtube-music:verify-cookies')]
#[Description('Check every connected YouTube Music cookie and flag the ones that stopped working')]
final class VerifyYouTubeMusicCookiesCommand extends Command
{
    public function handle(VerifyYouTubeMusicCookie $verify): int
    {
        $expired = 0;

        YouTubeMusicAccount::query()->lazyById()->each(function (YouTubeMusicAccount $account) use ($verify, &$expired): void {
            if (! $verify->handle($account)) {
                $expired++;
            }
        });

        $this->components->info("Verified YouTube Music cookies, {$expired} expired.");

        return self::SUCCESS;
    }
}
