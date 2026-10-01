<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

use App\Enums\CreditType;

/**
 * One contribution to a recording as a source states it.
 */
final readonly class Credit
{
    /**
     * @param  string  $role  The source's own wording ("mix", "ComposerLyricist"); '' when it gives none.
     * @param  list<string>  $attributes
     */
    public function __construct(
        public string $name,
        public CreditType $type,
        public string $role,
        public ?string $mbid,
        public ?string $ipi,
        public array $attributes = [],
    ) {}

    public function key(): string
    {
        return implode('|', [$this->type->value, $this->name, $this->role, $this->mbid ?? '', $this->ipi ?? '']);
    }
}
