<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DescribeYouTubeVideos;
use App\Http\Requests\LookupVideosRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

final readonly class VideoLookupController
{
    public function store(LookupVideosRequest $request, #[CurrentUser] User $user, DescribeYouTubeVideos $describe): JsonResponse
    {
        $videoIds = $request->videoIds();
        $listId = $request->validated('listId');

        Tracer::activeSpan()->setAttributes([
            'sonder.client' => 'extension',
            'sonder.extension.action' => 'lookup',
            'sonder.extension.items' => count($videoIds),
        ]);

        return response()->json($describe->handle($user, $videoIds, is_string($listId) ? $listId : null));
    }
}
