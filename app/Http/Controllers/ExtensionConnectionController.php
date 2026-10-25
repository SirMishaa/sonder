<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ExtensionTokenData;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Connects the browser extension: hands it a token it signs in with, and
 * lists or revokes the tokens already handed out.
 */
final readonly class ExtensionConnectionController
{
    public const string TOKEN_NAME = 'extension';

    public function edit(#[CurrentUser] User $user): Response
    {
        return Inertia::render('extension-connection/Edit', [
            'tokens' => $user->tokens()
                ->where('name', self::TOKEN_NAME)
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): ExtensionTokenData => ExtensionTokenData::fromModel($token))
                ->values(),
        ]);
    }

    public function store(#[CurrentUser] User $user): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken(self::TOKEN_NAME, [self::TOKEN_NAME])->plainTextToken,
        ]);
    }

    public function destroy(#[CurrentUser] User $user, string $tokenId): RedirectResponse
    {
        $user->tokens()
            ->where('name', self::TOKEN_NAME)
            ->whereKey($tokenId)
            ->firstOrFail()
            ->delete();

        return back();
    }
}
