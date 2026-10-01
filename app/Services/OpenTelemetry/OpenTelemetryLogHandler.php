<?php

declare(strict_types=1);

namespace App\Services\OpenTelemetry;

use Keepsuit\LaravelOpenTelemetry\Facades\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use OpenTelemetry\SDK\Common\Exception\StackTraceFormatter;
use Throwable;

/**
 * Replaces the package's `otlp` log channel handler, which passes the log
 * context through as is. A reported exception sits in that context as an
 * object, and the OTLP exporter can only serialise scalars and arrays, so it
 * reached Grafana as an empty value: no type, no stack trace, no cause.
 *
 * The exception is sent instead as the semantic convention attributes, with
 * a stack trace that follows the chain of previous exceptions, which is
 * where the actual reason usually is.
 */
final class OpenTelemetryLogHandler extends AbstractProcessingHandler
{
    public function write(LogRecord $record): void
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            unset($context['exception']);

            $context['exception.type'] = $exception::class;
            $context['exception.message'] = $exception->getMessage();
            $context['exception.stacktrace'] = StackTraceFormatter::format($exception);
        }

        Logger::log($record->level->toPsrLogLevel(), $record->message, $context);
    }
}
