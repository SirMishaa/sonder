<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PlayerCommandData;
use App\Data\PlayerCommandItemData;
use App\Events\PlayerCommandIssued;
use App\Models\ExtensionCommand;
use App\Models\User;

/**
 * Records a command from the browser extension, applies it to the stored
 * queue and tells the open Sonder tabs to replay it.
 */
final readonly class IssuePlayerCommand
{
    public function __construct(private ApplyPlayerCommand $apply) {}

    public function handle(User $user, PlayerCommandData $command, ?string $traceparent): ExtensionCommand
    {
        $logged = ExtensionCommand::query()->create([
            'user_id' => $user->id,
            'action' => $command->action,
            'kind' => $command->kind,
            'list_id' => $command->listId,
            'video_ids' => array_map(fn (PlayerCommandItemData $item): string => $item->videoId, $command->items),
        ]);

        $this->apply->handle($user, $command);

        event(new PlayerCommandIssued($logged, $command, $traceparent));

        return $logged;
    }
}
