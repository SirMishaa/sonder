<?php

declare(strict_types=1);

use App\Services\OpenTelemetry\OtlpClientCertificate;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = storage_path('framework/testing/otel-'.bin2hex(random_bytes(4)));
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('does nothing when no client certificate is configured', function (): void {
    expect(OtlpClientCertificate::materialize(null, null, $this->directory))->toBeEmpty()
        ->and(File::isDirectory($this->directory))->toBeFalse();
});

it('writes the base64 certificate and key to private files and returns their paths', function (): void {
    $paths = OtlpClientCertificate::materialize(
        base64_encode("-----BEGIN CERTIFICATE-----\ncert\n-----END CERTIFICATE-----\n"),
        base64_encode("-----BEGIN EC PRIVATE KEY-----\nkey\n-----END EC PRIVATE KEY-----\n"),
        $this->directory,
    );

    $certificatePath = $this->directory.'/client-certificate.pem';
    $keyPath = $this->directory.'/client-key.pem';

    expect($paths)->toBe(['client_certificate' => $certificatePath, 'client_key' => $keyPath])
        ->and(File::get($certificatePath))->toContain('BEGIN CERTIFICATE')
        ->and(File::get($keyPath))->toContain('BEGIN EC PRIVATE KEY')
        ->and(fileperms($keyPath) & 0777)->toBe(0600)
        ->and(fileperms($certificatePath) & 0777)->toBe(0600);
});

it('ignores a certificate without its key', function (): void {
    expect(OtlpClientCertificate::materialize(base64_encode('cert'), null, $this->directory))->toBeEmpty();
});

it('rejects values that are not base64', function (): void {
    OtlpClientCertificate::materialize('%%%not-base64%%%', base64_encode('key'), $this->directory);
})->throws(InvalidArgumentException::class);
