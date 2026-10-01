<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\Data\TrackQuery;
use Throwable;

final class FakeCreditsFmGateway implements CreditsFmGateway
{
    /** @var array<string, string> "artist|title" => ISRC */
    public array $isrcs = [];

    /** @var array<string, array<string, mixed>> ISRC => payload */
    public array $details = [];

    public ?Throwable $failure = null;

    /** @var list<string> */
    public array $calls = [];

    public function resolveBatch(array $queries): array
    {
        $this->calls[] = 'resolve_batch';
        $this->fail();

        return array_map(fn (TrackQuery $query): ?string => $this->isrcs["{$query->artist}|{$query->title}"] ?? null, $queries);
    }

    public function isrc(string $isrc): ?array
    {
        $this->calls[] = "isrc:{$isrc}";
        $this->fail();

        return $this->details[$isrc] ?? null;
    }

    private function fail(): void
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
