<?php

declare(strict_types=1);

use App\Services\OpenTelemetry\OpenTelemetryLogHandler;
use Keepsuit\LaravelOpenTelemetry\Facades\Logger;
use Monolog\Level;
use Monolog\LogRecord;

it('sends a logged exception as OpenTelemetry exception attributes, causes included', function (): void {
    $exception = new RuntimeException('YouTube Music could not be reached.', previous: new Exception('Could not find account information.'));
    Logger::shouldReceive('log')->once()->withArgs(function (string $level, string $message, array $context): bool {
        expect($level)->toBe('error')
            ->and($message)->toBe('YouTube Music could not be reached.')
            ->and($context)->not->toHaveKey('exception')
            ->and($context['exception.type'])->toBe(RuntimeException::class)
            ->and($context['exception.message'])->toBe('YouTube Music could not be reached.')
            ->and($context['exception.stacktrace'])->toContain('Could not find account information.')
            ->and($context['user_id'])->toBe(7);

        return true;
    });

    (new OpenTelemetryLogHandler())->handle(new LogRecord(
        datetime: new DateTimeImmutable(),
        channel: 'otlp',
        level: Level::Error,
        message: 'YouTube Music could not be reached.',
        context: ['exception' => $exception, 'user_id' => 7],
    ));
});

it('is the handler of the otlp log channel', function (): void {
    expect(config('logging.channels.otlp.handler'))->toBe(OpenTelemetryLogHandler::class);
});
