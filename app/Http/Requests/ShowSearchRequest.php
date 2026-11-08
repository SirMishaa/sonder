<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CatalogFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShowSearchRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::enum(CatalogFilter::class)],
        ];
    }

    /**
     * The search text, squished; empty when none was given.
     */
    public function searchText(): string
    {
        return (string) str($this->validated('q') ?? '')->squish();
    }

    public function filter(): ?CatalogFilter
    {
        return CatalogFilter::tryFrom((string) $this->validated('type'));
    }
}
