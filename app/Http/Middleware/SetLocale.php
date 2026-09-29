<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves every request in the user's chosen language. Guests and users who
 * never chose keep the application default (`app.locale`, French).
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if ($locale instanceof Locale) {
            App::setLocale($locale->value);
        }

        return $next($request);
    }
}
