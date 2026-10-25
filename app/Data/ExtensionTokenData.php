<?php

declare(strict_types=1);

namespace App\Data;

use Laravel\Sanctum\PersonalAccessToken;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A token the browser extension signs in with, as the settings page lists it.
 */
#[TypeScript]
final class ExtensionTokenData extends Data
{
    public function __construct(
        public string $id,
        public string $createdAt,
        public ?string $lastUsedAt,
    ) {}

    public static function fromModel(PersonalAccessToken $token): self
    {
        return new self(
            id: (string) $token->id,
            createdAt: $token->created_at?->toIso8601String() ?? '',
            lastUsedAt: $token->last_used_at?->toIso8601String(),
        );
    }
}
