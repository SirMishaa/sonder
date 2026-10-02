<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/**
 * Fakes the `cloud` CLI describing a production environment whose key is
 * `$productionKey`, and every PostgreSQL client call.
 */
function fakeCloudProduction(string $productionKey, int $dumpExitCode = 0): void
{
    Process::fake([
        '*environment:get*' => Process::result(json_encode([
            'databaseSchemaId' => '111869085',
            'environmentVariables' => [
                ['key' => 'SESSION_DRIVER', 'value' => 'database'],
                ['key' => 'APP_KEY', 'value' => 'base64:'.base64_encode($productionKey)],
            ],
        ], JSON_THROW_ON_ERROR)),
        '*database-cluster:list*' => Process::result(json_encode([
            ['connection' => ['hostname' => 'other.pg.laravel.cloud', 'port' => 5432, 'username' => 'laravel', 'password' => 'other-secret'], 'schemas' => [['id' => '111665778', 'name' => 'obsvr']]],
            ['connection' => ['hostname' => 'ep-sonder.pg.laravel.cloud', 'port' => 5432, 'username' => 'laravel', 'password' => 'prod-secret'], 'schemas' => [['id' => '111869085', 'name' => 'sonder']]],
        ], JSON_THROW_ON_ERROR)),
        '*FROM pg_tables*' => Process::result('12'),
        "'pg_dump'*" => Process::result(errorOutput: "pg_dump: dumping contents of table \"public.tracks\"\n", exitCode: $dumpExitCode),
        "'psql'*" => Process::result(),
        "'pg_restore'*" => Process::result(),
    ]);
}

/**
 * Runs `cloud:pull` with its console output mocked.
 *
 * @param  array<string, mixed>  $parameters
 */
function pullCommand(array $parameters = []): PendingCommand
{
    $command = artisan('cloud:pull', $parameters);

    return $command instanceof PendingCommand ? $command : throw new LogicException('The console output is not mocked.');
}

/**
 * The arguments of a faked process.
 *
 * @return list<string>
 */
function argumentsOf(PendingProcess $process): array
{
    return is_array($process->command) ? array_values($process->command) : [];
}

it('copies the environment database and re-encrypts its secrets with the local key', function (): void {
    $production = new Encrypter($productionKey = Encrypter::generateKey(config()->string('app.cipher')), config()->string('app.cipher'));
    $account = YouTubeMusicAccount::factory()->create();
    $user = User::factory()->create();
    DB::table('youtube_music_accounts')->update(['cookie' => $production->encryptString('SID=production')]);
    DB::table('users')->update(['two_factor_secret' => $production->encrypt('totp-secret'), 'two_factor_recovery_codes' => null]);
    fakeCloudProduction($productionKey);

    pullCommand(['--force' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => argumentsOf($process)[0] === 'pg_dump'
        && $process->environment['PGPASSWORD'] === 'prod-secret'
        && in_array('--host=ep-sonder.pg.laravel.cloud', argumentsOf($process), true)
        && in_array('--dbname=sonder', argumentsOf($process), true)
        && in_array('--exclude-table-data=sessions', argumentsOf($process), true)
        && in_array('--exclude-table-data=jobs', argumentsOf($process), true));
    Process::assertRan(fn (PendingProcess $process): bool => argumentsOf($process)[0] === 'pg_restore'
        && array_last(argumentsOf($process)) === storage_path('app/dumps/production.dump'));
    expect($account->fresh()?->cookie)->toBe('SID=production')
        ->and(decrypt((string) $user->fresh()?->two_factor_secret))->toBe('totp-secret');
});

it('leaves the local database alone when the dump fails', function (): void {
    fakeCloudProduction(Encrypter::generateKey(config()->string('app.cipher')), dumpExitCode: 1);

    expect(fn () => pullCommand(['--force' => true])->run())->toThrow(ProcessFailedException::class);

    Process::assertDidntRun(fn (PendingProcess $process): bool => argumentsOf($process)[0] === 'pg_restore'
        || in_array('--command=DROP SCHEMA public CASCADE; CREATE SCHEMA public;', argumentsOf($process), true));
});

it('asks before replacing the local database', function (): void {
    Process::fake();

    pullCommand()
        ->expectsConfirmation('Replace every table of the local database ['.config()->string('database.connections.pgsql.database').'] with a copy of [production]?', 'no')
        ->expectsOutputToContain('Nothing changed')
        ->assertFailed();

    Process::assertNothingRan();
});

it('never runs in production', function (): void {
    Process::fake();
    app()->detectEnvironment(fn (): string => 'production');

    pullCommand(['--force' => true])->assertFailed();

    Process::assertNothingRan();
});
