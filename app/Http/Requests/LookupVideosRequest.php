<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class LookupVideosRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'videoIds' => ['required', 'array', 'min:1', 'max:100'],
            'videoIds.*' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'listId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return list<string>
     */
    public function videoIds(): array
    {
        /** @var list<string> $videoIds */
        $videoIds = array_values($this->validated('videoIds'));

        return $videoIds;
    }
}
