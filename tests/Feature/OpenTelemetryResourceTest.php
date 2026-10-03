<?php

declare(strict_types=1);

it('names the instance the telemetry comes from', function (): void {
    expect(config('opentelemetry.service_instance_id'))->toBeString()->not->toBeEmpty();
});
