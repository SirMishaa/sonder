<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\PlayerCommandAction;
use App\Enums\PlayerCommandKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A command from the browser extension: play, play next or queue a video
 * or the tracks of a mix.
 */
#[TypeScript]
final class PlayerCommandData extends Data
{
    /**
     * @param  string|null  $listId  The YouTube list the tracks come from, for a mix.
     * @param  list<PlayerCommandItemData>  $items
     */
    public function __construct(
        public PlayerCommandAction $action,
        public PlayerCommandKind $kind,
        public ?string $listId,
        public string $title,
        public array $items,
    ) {}
}
