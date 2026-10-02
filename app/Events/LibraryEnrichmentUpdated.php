<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\LibraryEnrichmentData;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class LibraryEnrichmentUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $userId,
        public readonly LibraryEnrichmentData $summary,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('library-enrichment.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'enrichment.updated';
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return $this->summary->toArray();
    }
}
