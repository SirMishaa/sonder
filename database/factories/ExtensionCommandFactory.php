<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerCommandAction;
use App\Enums\PlayerCommandKind;
use App\Models\ExtensionCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtensionCommand>
 */
final class ExtensionCommandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => PlayerCommandAction::Enqueue,
            'kind' => PlayerCommandKind::Video,
            'list_id' => null,
            'video_ids' => [fake()->regexify('[A-Za-z0-9_-]{11}')],
        ];
    }

    public function mix(string $listId): self
    {
        return $this->state(['kind' => PlayerCommandKind::Mix, 'list_id' => $listId]);
    }
}
