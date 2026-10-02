<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReencryptSecrets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Prompts\Progress;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Replaces the local database with a copy of a Laravel Cloud environment's,
 * through the `cloud` CLI and the PostgreSQL client tools. Transient tables
 * (sessions, cache, queue) come over empty, then local migrations run and
 * secrets are re-encrypted with the local key. Thumbnails are not copied:
 * the thumbnail endpoint mirrors them again on first display.
 */
#[Signature('cloud:pull {environment=production : The Laravel Cloud environment to copy} {--force : Skip the confirmation}')]
#[Description('Replace the local database with a copy of a Laravel Cloud environment')]
final class CloudPullCommand extends Command
{
    /**
     * @var list<string>
     */
    public const array TABLES_WITHOUT_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    /**
     * @var list<string>
     */
    private const array SUMMARY_TABLES = ['users', 'playlists', 'tracks', 'listens', 'recordings', 'contributors', 'enrichments'];

    public function handle(ReencryptSecrets $reencryptSecrets): int
    {
        /** @var string $environment */
        $environment = $this->argument('environment');
        /** @var array{driver: string, host: string, port: int|string, database: string, username: string, password: string|null} $local */
        $local = config('database.connections.'.config()->string('database.default'));

        if (app()->isProduction()) {
            error('This command replaces the local database, it never runs in production.');

            return self::FAILURE;
        }

        if ($local['driver'] !== 'pgsql') {
            error('The local database must be PostgreSQL.');

            return self::FAILURE;
        }

        intro(" Pulling {$environment} into [{$local['database']}] ");

        if (! $this->option('force') && ! confirm("Replace every table of the local database [{$local['database']}] with a copy of [{$environment}]?", default: false)) {
            warning('Nothing changed. Without a terminal to answer, pass --force.');

            return self::FAILURE;
        }

        [$remote, $appKey] = spin(fn (): array => $this->describe($environment), "Reading {$environment} from Laravel Cloud…");
        $dump = storage_path("app/dumps/{$environment}.dump");
        File::ensureDirectoryExists(dirname($dump));

        $this->dump($remote, $dump);

        $connection = ['--host='.$local['host'], '--port='.$local['port'], '--username='.$local['username'], '--dbname='.$local['database']];
        $localPassword = ['PGPASSWORD' => (string) $local['password']];

        spin(fn (): ProcessResult => Process::env($localPassword)
            ->run(['psql', ...$connection, '--no-psqlrc', '--set=ON_ERROR_STOP=1', '--command=DROP SCHEMA public CASCADE; CREATE SCHEMA public;'])
            ->throw(), 'Emptying the local database…');

        $this->restore($dump, $connection, $localPassword);

        spin(fn (): int => $this->callSilently('migrate', ['--force' => true]), 'Running local migrations…');
        $reencrypted = spin(
            fn (): int => $reencryptSecrets->handle(new Encrypter($appKey, config()->string('app.cipher'))),
            'Re-encrypting secrets with the local key…',
        );

        table(['Table', 'Rows'], array_map(
            fn (string $table): array => [$table, number_format(DB::table($table)->count())],
            self::SUMMARY_TABLES,
        ));
        outro("Copied {$environment}, {$reencrypted} ".Str::plural('secret', $reencrypted)." re-encrypted. Sign in with your {$environment} password.");

        return self::SUCCESS;
    }

    /**
     * Dumps the remote database, one progress step per table copied.
     *
     * @param  array{host: string, port: int, username: string, password: string, database: string}  $remote
     */
    private function dump(array $remote, string $file): void
    {
        $connection = ['--host='.$remote['host'], '--port='.$remote['port'], '--username='.$remote['username'], '--dbname='.$remote['database']];
        $pending = Process::timeout(600)->env(['PGPASSWORD' => $remote['password'], 'PGSSLMODE' => 'require']);
        $excluded = implode(',', array_map(fn (string $table): string => "'{$table}'", self::TABLES_WITHOUT_DATA));

        $tables = (int) mb_trim(spin(fn (): string => (clone $pending)
            ->run(['psql', ...$connection, '--no-psqlrc', '--tuples-only', '--no-align', "--command=SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename NOT IN ({$excluded})"])
            ->throw()
            ->output(), "Counting the tables of [{$remote['database']}]…"));

        $this->withProgress(
            $pending,
            [
                'pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--verbose', ...$connection,
                ...array_map(fn (string $table): string => "--exclude-table-data={$table}", self::TABLES_WITHOUT_DATA),
                '--file='.$file,
            ],
            progress("Downloading [{$remote['database']}]", max(1, $tables)),
            '/dumping contents of table "(?:public\.)?(?<subject>[^"]+)"/',
        );
    }

    /**
     * Restores the dump, one progress step per entry of its table of contents.
     *
     * @param  list<string>  $connection
     * @param  array<string, string>  $password
     */
    private function restore(string $dump, array $connection, array $password): void
    {
        $entries = collect(explode("\n", Process::run(['pg_restore', '--list', $dump])->throw()->output()))
            ->filter(fn (string $line): bool => mb_trim($line) !== '' && ! str_starts_with($line, ';'))
            ->count();

        $this->withProgress(
            Process::timeout(600)->env($password),
            ['pg_restore', ...$connection, '--no-owner', '--no-privileges', '--exit-on-error', '--verbose', $dump],
            progress('Restoring locally', max(1, $entries)),
            '/^pg_restore: (?:creating|processing data for table|executing) (?<subject>.+)$/m',
        );
    }

    /**
     * Runs a verbose PostgreSQL tool, advancing the bar for every line of its
     * output matching the pattern and showing what it works on.
     *
     * @param  list<string>  $command
     * @param  Progress<int<1, max>>  $progress
     */
    private function withProgress(PendingProcess $pending, array $command, Progress $progress, string $pattern): void
    {
        $progress->start();
        $buffer = '';

        $result = $pending->start($command, function (string $type, string $output) use ($progress, $pattern, &$buffer): void {
            $lines = explode("\n", $buffer.$output);
            $buffer = (string) array_pop($lines);

            foreach ($lines as $line) {
                if (preg_match($pattern, $line, $matches) === 1) {
                    $progress->hint(Str::limit(str_replace('"', '', $matches['subject']), 60));
                    $progress->advance();
                }
            }
        })->wait();

        if ($result->successful()) {
            $progress->hint('');
            $progress->finish();
        }

        $result->throw();
    }

    /**
     * Reads the environment's encryption key and the credentials of its
     * database from Laravel Cloud.
     *
     * @return array{0: array{host: string, port: int, username: string, password: string, database: string}, 1: string}
     */
    private function describe(string $environment): array
    {
        /** @var array{databaseSchemaId: string|null, environmentVariables: list<array{key: string, value: string}>} $details */
        $details = $this->cloud('environment:get', $environment);
        /** @var list<array{connection: array{hostname: string, port: int, username: string, password: string}, schemas: list<array{id: string, name: string}>}> $clusters */
        $clusters = $this->cloud('database-cluster:list');

        $appKey = collect($details['environmentVariables'])->firstWhere('key', 'APP_KEY')['value'] ?? null;

        foreach ($clusters as $cluster) {
            $schema = collect($cluster['schemas'])->firstWhere('id', $details['databaseSchemaId']);

            if ($schema !== null && is_string($appKey)) {
                return [
                    [
                        'host' => $cluster['connection']['hostname'],
                        'port' => $cluster['connection']['port'],
                        'username' => $cluster['connection']['username'],
                        'password' => $cluster['connection']['password'],
                        'database' => $schema['name'],
                    ],
                    Str::startsWith($appKey, 'base64:') ? (string) base64_decode(Str::after($appKey, 'base64:'), true) : $appKey,
                ];
            }
        }

        throw new RuntimeException("Laravel Cloud environment [{$environment}] has no APP_KEY or no attached database.");
    }

    /**
     * Runs a read command of the `cloud` CLI and decodes its JSON, secrets
     * included.
     *
     * @return array<array-key, mixed>
     */
    private function cloud(string ...$arguments): array
    {
        $output = Process::timeout(60)->run(['cloud', ...$arguments, '--json', '--show-sensitive', '-n'])->throw()->output();

        return (array) json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
