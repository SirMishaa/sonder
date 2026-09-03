<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConnectYouTubeMusicAccount;
use App\Actions\DisconnectYouTubeMusicAccount;
use App\Exceptions\YouTubeMusicException;
use App\Http\Requests\CreateYouTubeMusicConnectionRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class YouTubeMusicConnectionController
{
    public function create(#[CurrentUser] User $user): Response
    {
        return Inertia::render('youtube-music-connection/Create', [
            'account' => $user->youTubeMusicAccount()->first()?->only('account_name', 'last_verified_at'),
        ]);
    }

    public function store(
        CreateYouTubeMusicConnectionRequest $request,
        #[CurrentUser] User $user,
        ConnectYouTubeMusicAccount $action,
    ): RedirectResponse {
        try {
            $action->handle($user, $request->string('cookie')->value());
        } catch (YouTubeMusicException) {
            return back()->withErrors([
                'cookie' => 'YouTube Music rejected this cookie. Make sure you are signed in, and copy the header again.',
            ]);
        }

        return to_route('playlist.index');
    }

    public function destroy(#[CurrentUser] User $user, DisconnectYouTubeMusicAccount $action): RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account !== null) {
            $action->handle($account);
        }

        return to_route('youtube-music-connection.create');
    }
}
