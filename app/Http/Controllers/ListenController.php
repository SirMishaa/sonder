<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordListen;
use App\Http\Requests\CreateListenRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class ListenController
{
    public function store(CreateListenRequest $request, #[CurrentUser] User $user, RecordListen $record): Response
    {
        /** @var array{id: string, youtube_video_id: string, title: string, artists: string, youtube_playlist_id?: string|null, origin: string, end_reason: string, started_at: string, ended_at: string, position_seconds: int, listened_seconds: int, duration_seconds?: int|null} $attributes */
        $attributes = $request->validated();

        $record->handle($user, $attributes);

        return response()->noContent();
    }
}
