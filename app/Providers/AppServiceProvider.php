<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\YouTubeMusic\CachedClient;
use App\Services\YouTubeMusic\Client;
use App\Services\YouTubeMusic\YtmusicapiClient;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Client::class, fn (): Client => new CachedClient(
            new YtmusicapiClient(),
            $this->app->make(Repository::class),
        ));

        Gate::define('viewInertiaDevTools', fn (User $user): bool => $user->email === 'mishaa.pro@proton.me');
    }
}
