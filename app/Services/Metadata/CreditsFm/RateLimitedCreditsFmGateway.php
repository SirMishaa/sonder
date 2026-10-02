<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\CallBudget;

/**
 * Spends the `credits-fm` budget before every call, sleeping through a short wait.
 */
final readonly class RateLimitedCreditsFmGateway implements CreditsFmGateway
{
    public function __construct(
        private CreditsFmGateway $gateway,
        private CallBudget $budget,
    ) {}

    public function resolveBatch(array $queries): array
    {
        $this->spend();

        return $this->gateway->resolveBatch($queries);
    }

    public function isrc(string $isrc): ?array
    {
        $this->spend();

        return $this->gateway->isrc($isrc);
    }

    private function spend(): void
    {
        $wait = $this->budget->await(CallBudget::CREDITS_FM);

        if ($wait !== null) {
            throw new MetadataSourceRateLimited(MetadataSource::CreditsFm, $wait);
        }
    }
}
