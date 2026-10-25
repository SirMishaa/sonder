<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IssuePlayerCommand;
use App\Http\Requests\IssuePlayerCommandRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

final readonly class PlayerCommandController
{
    public function store(IssuePlayerCommandRequest $request, #[CurrentUser] User $user, IssuePlayerCommand $issue): JsonResponse
    {
        $command = $request->command();

        Tracer::activeSpan()->setAttributes([
            'sonder.client' => 'extension',
            'sonder.extension.action' => $command->action->value,
            'sonder.extension.kind' => $command->kind->value,
            'sonder.extension.items' => count($command->items),
        ]);

        $logged = $issue->handle($user, $command, $request->header('traceparent'));

        return response()->json(['id' => $logged->id], 202);
    }
}
