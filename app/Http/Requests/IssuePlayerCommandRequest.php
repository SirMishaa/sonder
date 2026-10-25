<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\PlayerCommandData;
use App\Data\PlayerCommandItemData;
use App\Enums\PlayerCommandAction;
use App\Enums\PlayerCommandKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssuePlayerCommandRequest extends FormRequest
{
    /**
     * The first page of a YouTube mix stays well under this.
     */
    public const int MAX_ITEMS = 50;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(PlayerCommandAction::class)],
            'kind' => ['required', Rule::enum(PlayerCommandKind::class)],
            'listId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.videoId' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.artists' => ['present', 'nullable', 'string', 'max:255'],
            'items.*.durationSeconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'items.*.thumbnailUrl' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function command(): PlayerCommandData
    {
        /** @var array{action: string, kind: string, listId?: string|null, title: string, items: list<array{videoId: string, title: string, artists: string|null, durationSeconds: int, thumbnailUrl?: string|null}>} $attributes */
        $attributes = $this->validated();

        return new PlayerCommandData(
            action: PlayerCommandAction::from($attributes['action']),
            kind: PlayerCommandKind::from($attributes['kind']),
            listId: $attributes['listId'] ?? null,
            title: $attributes['title'],
            items: array_map(fn (array $item): PlayerCommandItemData => new PlayerCommandItemData(
                videoId: $item['videoId'],
                title: $item['title'],
                artists: $item['artists'] ?? '',
                durationSeconds: $item['durationSeconds'],
                thumbnailUrl: $item['thumbnailUrl'] ?? null,
            ), $attributes['items']),
        );
    }
}
