<?php

declare(strict_types=1);

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('serves the request in the language the user chose', function (): void {
    $user = User::factory()->make(['locale' => Locale::French]);
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn (): User => $user);

    (new SetLocale())->handle($request, fn (): Response => response('OK'));

    expect(app()->getLocale())->toBe('fr_BE');
});

it('keeps the application default when the user never chose', function (): void {
    config(['app.locale' => 'en_US']);
    app()->setLocale('en_US');
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn (): User => User::factory()->make(['locale' => null]));

    (new SetLocale())->handle($request, fn (): Response => response('OK'));

    expect(app()->getLocale())->toBe('en_US');
});
