<?php

declare(strict_types=1);

namespace App\Services\OpenTelemetry;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * The OTLP spec takes the mTLS client certificate and key as file paths
 * (OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE / OTEL_EXPORTER_OTLP_CLIENT_KEY), but
 * Laravel Cloud only offers environment variables. The PEMs travel base64
 * encoded in the environment and are written to private files on boot, before
 * the OpenTelemetry package builds its exporters.
 */
final class OtlpClientCertificate
{
    /**
     * @return array{}|array{client_certificate: string, client_key: string}
     */
    public static function materialize(?string $certificate, ?string $key, string $directory): array
    {
        if ($certificate === null || $certificate === '' || $key === null || $key === '') {
            return [];
        }

        File::ensureDirectoryExists($directory, 0700);

        return [
            'client_certificate' => self::writePrivateFile($directory.'/client-certificate.pem', self::decode($certificate)),
            'client_key' => self::writePrivateFile($directory.'/client-key.pem', self::decode($key)),
        ];
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode($value, strict: true);

        throw_if($decoded === false || $decoded === '', InvalidArgumentException::class, 'OTLP client certificate and key must be base64 encoded PEM.');

        return $decoded;
    }

    /**
     * Octane workers boot concurrently: write to a temporary file and rename it
     * so no worker ever reads a half-written PEM.
     */
    private static function writePrivateFile(string $path, string $contents): string
    {
        if (File::exists($path) && File::get($path) === $contents) {
            return $path;
        }

        $temporaryPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporaryPath, $contents);
        File::chmod($temporaryPath, 0600);
        File::move($temporaryPath, $path);

        return $path;
    }
}
