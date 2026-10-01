<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use Throwable;

/**
 * Every failure a provider adapter may raise. Domain code and controllers
 * catch this contract only, so they never depend on a provider's native
 * errors. `getMessage()` stays technical (logs, Grafana); `userMessage()` is
 * what a person reads.
 */
interface ProviderException extends Throwable
{
    public function provider(): Provider;

    public function errorCode(): ProviderErrorCode;

    /**
     * Placeholders for the user message.
     *
     * @return array<string, int|string>
     */
    public function context(): array;

    /**
     * Translated, in the current locale.
     */
    public function userMessage(): string;
}
