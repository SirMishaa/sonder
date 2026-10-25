<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\PlayerCommandData;
use App\Models\ExtensionCommand;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Tells the user's open Sonder tabs to replay a command from the extension.
 * The trace context rides along so the tab's follow-up joins the same trace.
 */
final class PlayerCommandIssued implements ShouldBroadcastNow
{
    public function __construct(
        public readonly ExtensionCommand $command,
        public readonly PlayerCommandData $data,
        public readonly ?string $traceparent,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('player.'.$this->command->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'player.command';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->command->id,
            ...$this->data->toArray(),
            'traceparent' => $this->traceparent,
        ];
    }
}
