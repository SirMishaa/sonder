<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListenOrigin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlayerQueueRequest extends FormRequest
{
    /**
     * The browser keeps a window of this many tracks around the current one.
     */
    public const int MAX_TRACKS = 300;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $trackCount = is_array($this->input('tracks')) ? count($this->input('tracks')) : 0;

        return [
            'tracks' => ['present', 'array', 'max:'.self::MAX_TRACKS],
            'tracks.*.key' => ['required', 'string', 'max:128', 'distinct'],
            'tracks.*.videoId' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9_-]+$/'],
            'tracks.*.title' => ['required', 'string', 'max:255'],
            'tracks.*.artists' => ['nullable', 'string', 'max:255'],
            'tracks.*.album' => ['nullable', 'string', 'max:255'],
            'tracks.*.duration' => ['nullable', 'string', 'max:16'],
            'tracks.*.durationSeconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'tracks.*.thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'tracks.*.playlistId' => ['nullable', 'string', 'max:64'],
            'tracks.*.queued' => ['sometimes', 'boolean'],
            'tracks.*.genres' => ['sometimes', 'array', 'max:3'],
            'tracks.*.genres.*' => ['string', 'max:64'],
            'index' => ['required', 'integer', 'min:-1', 'max:'.max(-1, $trackCount - 1)],
            'source' => ['nullable', 'array'],
            'source.playlistId' => ['nullable', 'string', 'max:64'],
            'source.title' => ['required_with:source', 'string', 'max:255'],
            'origin' => ['required', Rule::enum(ListenOrigin::class)],
        ];
    }
}
