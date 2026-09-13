# YouTube Music background sync + Mercure progress UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the YouTube Music library sync off the HTTP request (currently blocking `/playlists` for 20-30s) and onto a queued job, with a live, animated progress UI driven by Laravel's new native Mercure broadcast driver.

**Architecture:** A `YouTubeMusicSync` row tracks one sync attempt's progress. `StartYouTubeMusicSync` creates one (or reuses an active one) and dispatches `SyncYouTubeMusicLibrary`, which drives the existing `SyncPlaylistsFromYouTubeMusicAction` (now progress-callback-aware) and broadcasts a `YouTubeMusicSyncUpdated` event after every step on a private per-sync Mercure channel. The frontend subscribes with a small hand-written `EventSource` client (Echo's Mercure connector isn't published yet) and either blocks on a dedicated sync page (first sync) or shows a small badge (background refresh).

**Tech Stack:** Laravel 13 (`13.x-dev` for the Mercure driver), `symfony/mercure`, Octane + FrankenPHP's built-in Mercure hub, Inertia v3 + Vue 3, native browser `EventSource`.

**Spec:** `docs/superpowers/specs/2026-09-13-youtube-music-sync-job-design.md`

## Global Constraints

- `laravel/framework` is pinned to `13.x-dev` (per explicit approval) to get the native `mercure` broadcast driver ahead of its first tagged release.
- `symfony/mercure:^0.8` must be required explicitly — it is only a *suggested* dependency of `illuminate/broadcasting`.
- Mercure is enabled via `config('octane.mercure')`, **not** by hand-editing `Caddyfile` or setting `CADDY_SERVER_EXTRA_DIRECTIVES` — Octane's `StartFrankenPhpCommand` renders that env var itself from this config key.
- `MERCURE_JWT_SECRET` must be the same value used by both `config/broadcasting.php` (`secret`) and `config/octane.php` (`mercure.publisher_jwt` / `mercure.subscriber_jwt`), since the hub must verify tokens Laravel signs.
- No `laravel-echo` dependency — its Mercure connector isn't published on npm yet. Use a hand-written `EventSource` client instead.
- `$tries = 1` on `SyncYouTubeMusicLibrary` — a bad cookie will not fix itself on retry.
- Follow existing conventions: `declare(strict_types=1)`, `final` classes, constructor property promotion, explicit return types, Pest tests with `it(...)`, factories over manual model construction.
- After PHP changes: run `vendor/bin/pint --dirty --format agent`.
- After adding/changing `App\Data` or `App\Enums` classes: run `php artisan typescript:transform` so `resources/js/types/generated.d.ts` picks up the new types before touching Vue files that reference them.
- After adding/changing controller methods or routes: run `php artisan wayfinder:generate` so `resources/js/actions` / `resources/js/routes` stay in sync.

---

### Task 1: Enable the Mercure broadcasting driver

**Files:**
- Modify: `composer.json`
- Modify: `config/octane.php`
- Create: `config/broadcasting.php`
- Modify: `.env`
- Modify: `.env.example`

**Interfaces:**
- Produces: `BROADCAST_CONNECTION=mercure` app-wide; `broadcast()`/`Broadcast::channel()` become available for later tasks.

- [ ] **Step 1: Pin `laravel/framework` to `13.x-dev` and require `symfony/mercure`**

Run:

```bash
composer require laravel/framework:13.x-dev symfony/mercure:^0.8
```

- [ ] **Step 2: Add the Mercure connection to broadcasting config**

Create `config/broadcasting.php`:

```php
<?php

declare(strict_types=1);

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'mercure' => [
            'driver' => 'mercure',
            'secret' => env('MERCURE_JWT_SECRET'),
            'subscribe_expiration' => 15,
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
```

- [ ] **Step 3: Enable Octane's FrankenPHP Mercure hub**

In `config/octane.php`, add a `'mercure'` key. Find the closing `];` of the file and add this key before it (alongside `'watch'`, `'garbage'`, etc.):

```php
    /*
    |--------------------------------------------------------------------------
    | Mercure Hub
    |--------------------------------------------------------------------------
    |
    | FrankenPHP ships a built-in Mercure hub. Setting this key makes Octane's
    | FrankenPHP server command render the matching `mercure { ... }` Caddy
    | directive on every boot, in both local dev and on Laravel Cloud. The
    | secret must match `broadcasting.connections.mercure.secret`, since the
    | hub has to verify subscriber/publisher tokens Laravel signs.
    |
    */

    'mercure' => array_filter([
        'publisher_jwt' => env('MERCURE_JWT_SECRET'),
        'subscriber_jwt' => env('MERCURE_JWT_SECRET'),
    ]),
```

- [ ] **Step 4: Add the env vars**

In `.env.example`, change `BROADCAST_CONNECTION=log` to:

```
BROADCAST_CONNECTION=mercure
MERCURE_JWT_SECRET=
```

In `.env`, change `BROADCAST_CONNECTION=log` to `BROADCAST_CONNECTION=mercure` and add a real secret (32+ bytes):

```bash
php -r "echo 'MERCURE_JWT_SECRET='.bin2hex(random_bytes(32)).PHP_EOL;" >> .env
```

Then remove the now-duplicate `BROADCAST_CONNECTION=log` line and replace it with `BROADCAST_CONNECTION=mercure` in `.env`.

- [ ] **Step 5: Verify nothing broke**

Run:

```bash
composer install
php artisan config:clear
php artisan test --compact
```

Expected: the full suite still passes (test env uses `BROADCAST_CONNECTION=null` from `phpunit.xml`, so this step only changes local/prod config).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/octane.php config/broadcasting.php .env.example
git commit -m "feat: enable the Mercure broadcasting driver via Octane's FrankenPHP hub"
```

(`.env` is gitignored — do not commit it.)

---

### Task 2: `YouTubeMusicSync` model, enum, migration, factory

**Files:**
- Create: `app/Enums/YouTubeMusicSyncStatus.php`
- Create: `database/migrations/2026_09_13_120000_create_youtube_music_syncs_table.php`
- Create: `app/Models/YouTubeMusicSync.php`
- Create: `database/factories/YouTubeMusicSyncFactory.php`
- Test: `tests/Unit/Models/YouTubeMusicSyncTest.php`

**Interfaces:**
- Produces: `YouTubeMusicSync` model with `youtubeMusicAccount(): BelongsTo`, `isOwnedBy(User $user): bool`; `YouTubeMusicSyncStatus` enum (`Pending`, `Syncing`, `Completed`, `Failed`); `YouTubeMusicSync::factory()`.

- [ ] **Step 1: Write the failing model test**

Create `tests/Unit/Models/YouTubeMusicSyncTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;

it('belongs to a youtube music account', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    expect($sync->youtubeMusicAccount->id)->toBe($account->id);
});

it('defaults to pending with zero playlists synced', function (): void {
    $sync = YouTubeMusicSync::factory()->create();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Pending)
        ->and($sync->synced_playlists)->toBe(0)
        ->and($sync->total_playlists)->toBeNull();
});

it('knows whether a user owns it, through its account', function (): void {
    $owner = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($owner)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    expect($sync->isOwnedBy($owner))->toBeTrue()
        ->and($sync->isOwnedBy(User::factory()->create()))->toBeFalse();
});

it('goes away with its account', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $account->delete();

    expect(YouTubeMusicSync::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Unit/Models/YouTubeMusicSyncTest.php --compact
```

Expected: FAIL — class `App\Models\YouTubeMusicSync` not found.

- [ ] **Step 3: Create the enum**

Create `app/Enums/YouTubeMusicSyncStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum YouTubeMusicSyncStatus: string
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Create the migration**

Create `database/migrations/2026_09_13_120000_create_youtube_music_syncs_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_music_syncs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('youtube_music_account_id')->constrained('youtube_music_accounts')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_playlists')->nullable();
            $table->unsignedInteger('synced_playlists')->default(0);
            $table->string('current_playlist_title')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['youtube_music_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_music_syncs');
    }
};
```

- [ ] **Step 5: Create the model**

Create `app/Models/YouTubeMusicSync.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\YouTubeMusicSyncStatus;
use Carbon\CarbonInterface;
use Database\Factories\YouTubeMusicSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $youtube_music_account_id
 * @property-read YouTubeMusicSyncStatus $status
 * @property-read int|null $total_playlists
 * @property-read int $synced_playlists
 * @property-read string|null $current_playlist_title
 * @property-read string|null $error_message
 * @property-read CarbonInterface|null $started_at
 * @property-read CarbonInterface|null $finished_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read YouTubeMusicAccount $youtubeMusicAccount
 */
#[Table(name: 'youtube_music_syncs')]
final class YouTubeMusicSync extends Model
{
    /** @use HasFactory<YouTubeMusicSyncFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'youtube_music_account_id',
        'status',
        'total_playlists',
        'synced_playlists',
        'current_playlist_title',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'youtube_music_account_id' => 'string',
            'status' => YouTubeMusicSyncStatus::class,
            'total_playlists' => 'integer',
            'synced_playlists' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<YouTubeMusicAccount, $this>
     */
    public function youtubeMusicAccount(): BelongsTo
    {
        return $this->belongsTo(YouTubeMusicAccount::class, 'youtube_music_account_id');
    }

    /**
     * Whether the given user owns the account this sync belongs to. This is
     * the single source of truth `routes/channels.php` authorizes against.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->youtubeMusicAccount->user_id === $user->id;
    }
}
```

- [ ] **Step 6: Create the factory**

Create `database/factories/YouTubeMusicSyncFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YouTubeMusicSync>
 */
final class YouTubeMusicSyncFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_music_account_id' => YouTubeMusicAccount::factory(),
            'status' => YouTubeMusicSyncStatus::Pending,
            'synced_playlists' => 0,
        ];
    }
}
```

- [ ] **Step 7: Run migrations and the test**

```bash
php artisan migrate
php artisan test tests/Unit/Models/YouTubeMusicSyncTest.php --compact
```

Expected: PASS (4 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/YouTubeMusicSyncStatus.php app/Models/YouTubeMusicSync.php \
  database/migrations/2026_09_13_120000_create_youtube_music_syncs_table.php \
  database/factories/YouTubeMusicSyncFactory.php tests/Unit/Models/YouTubeMusicSyncTest.php
git commit -m "feat: add YouTubeMusicSync model to track sync progress"
```

---

### Task 3: `YouTubeMusicSyncData` DTO

**Files:**
- Create: `app/Data/YouTubeMusicSyncData.php`
- Test: `tests/Unit/Data/YouTubeMusicSyncDataTest.php`

**Interfaces:**
- Consumes: `App\Models\YouTubeMusicSync` (Task 2), `App\Enums\YouTubeMusicSyncStatus` (Task 2).
- Produces: `YouTubeMusicSyncData::fromModel(YouTubeMusicSync $sync): self` with public properties `id: string`, `status: YouTubeMusicSyncStatus`, `totalPlaylists: ?int`, `syncedPlaylists: int`, `currentPlaylistTitle: ?string`, `errorMessage: ?string`. Used by the broadcast event (Task 5), the sync page controller (Task 8), and the playlist index controller (Task 9). TypeScript type `App.Data.YouTubeMusicSyncData`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Data/YouTubeMusicSyncDataTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\YouTubeMusicSyncData;
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicSync;

it('maps a sync model to its data representation', function (): void {
    $sync = YouTubeMusicSync::factory()->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'total_playlists' => 10,
        'synced_playlists' => 3,
        'current_playlist_title' => 'Deep Focus',
        'error_message' => null,
    ]);

    $data = YouTubeMusicSyncData::fromModel($sync);

    expect($data->id)->toBe($sync->id)
        ->and($data->status)->toBe(YouTubeMusicSyncStatus::Syncing)
        ->and($data->totalPlaylists)->toBe(10)
        ->and($data->syncedPlaylists)->toBe(3)
        ->and($data->currentPlaylistTitle)->toBe('Deep Focus')
        ->and($data->errorMessage)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Unit/Data/YouTubeMusicSyncDataTest.php --compact
```

Expected: FAIL — class `App\Data\YouTubeMusicSyncData` not found.

- [ ] **Step 3: Create the Data class**

Create `app/Data/YouTubeMusicSyncData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicSync;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class YouTubeMusicSyncData extends Data
{
    public function __construct(
        public string $id,
        public YouTubeMusicSyncStatus $status,
        public ?int $totalPlaylists,
        public int $syncedPlaylists,
        public ?string $currentPlaylistTitle,
        public ?string $errorMessage,
    ) {}

    public static function fromModel(YouTubeMusicSync $sync): self
    {
        return new self(
            id: $sync->id,
            status: $sync->status,
            totalPlaylists: $sync->total_playlists,
            syncedPlaylists: $sync->synced_playlists,
            currentPlaylistTitle: $sync->current_playlist_title,
            errorMessage: $sync->error_message,
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test tests/Unit/Data/YouTubeMusicSyncDataTest.php --compact
```

Expected: PASS.

- [ ] **Step 5: Generate TypeScript types**

```bash
php artisan typescript:transform
```

Expected: `resources/js/types/generated.d.ts` now contains `YouTubeMusicSyncData` and `YouTubeMusicSyncStatus`.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/YouTubeMusicSyncData.php tests/Unit/Data/YouTubeMusicSyncDataTest.php resources/js/types/generated.d.ts
git commit -m "feat: add YouTubeMusicSyncData DTO"
```

---

### Task 4: Progress callback + per-playlist transactions in the sync action

**Files:**
- Modify: `app/Actions/SyncPlaylistsFromYouTubeMusicAction.php`
- Test: `tests/Unit/Actions/SyncPlaylistsFromYouTubeMusicActionTest.php` (new)

**Interfaces:**
- Produces: `SyncPlaylistsFromYouTubeMusicAction::handle(YouTubeMusicAccount $account, ?Closure $onProgress = null): void`, where `$onProgress` is called as `(int $synced, int $total, ?Playlist $playlist)` — once with `(0, $total, null)` right after the playlist list is fetched, then once per playlist right after it's fully upserted. Consumed by `SyncYouTubeMusicLibrary` (Task 6).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Actions/SyncPlaylistsFromYouTubeMusicActionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use Tests\Support\FakeYouTubeMusicClient;

it('upserts playlists and tracks from YouTube Music', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus', trackCount: 1),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus', trackCount: 1);

    resolve(SyncPlaylistsFromYouTubeMusicAction::class)->handle($account);

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect($playlist->title)->toBe('Deep Focus')
        ->and($playlist->tracks()->count())->toBe(1);
});

it('replaces the tracks of a playlist synced a second time', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', trackCount: 1);

    $action = resolve(SyncPlaylistsFromYouTubeMusicAction::class);
    $action->handle($account);
    $action->handle($account);

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect(Playlist::query()->count())->toBe(1)
        ->and($playlist->tracks()->count())->toBe(1);
});

it('reports progress after each playlist, with the total known up front', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2', title: 'Gaming');

    $calls = [];

    resolve(SyncPlaylistsFromYouTubeMusicAction::class)->handle(
        $account,
        function (int $synced, int $total, ?Playlist $playlist) use (&$calls): void {
            $calls[] = [$synced, $total, $playlist?->title];
        },
    );

    expect($calls)->toBe([
        [0, 2, null],
        [1, 2, 'Deep Focus'],
        [2, 2, 'Gaming'],
    ]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/Actions/SyncPlaylistsFromYouTubeMusicActionTest.php --compact
```

Expected: the third test FAILs (`handle()` does not accept a second argument yet); the first two pass already against the current implementation.

- [ ] **Step 3: Update the action**

Replace the contents of `app/Actions/SyncPlaylistsFromYouTubeMusicAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class SyncPlaylistsFromYouTubeMusicAction
{
    public function __construct(private Client $client) {}

    /**
     * @param  Closure(int $synced, int $total, ?Playlist $playlist): void|null  $onProgress
     *                                                                                       Called once with `(0, $total, null)` right after the playlist
     *                                                                                       list is fetched, then once per playlist right after it is fully
     *                                                                                       upserted (tracks included).
     */
    public function handle(YouTubeMusicAccount $account, ?Closure $onProgress = null): void
    {
        $playlistSummaries = $this->client->playlists($account->cookie);
        $total = count($playlistSummaries);

        $onProgress?->(0, $total, null);

        foreach ($playlistSummaries as $index => $summary) {
            $playlist = DB::transaction(function () use ($account, $summary): Playlist {
                $playlist = Playlist::updateOrCreate(
                    [
                        'youtube_music_account_id' => $account->id,
                        'youtube_playlist_id' => $summary->id,
                    ],
                    [
                        'title' => $summary->title,
                        'description' => $summary->description,
                        'track_count' => $summary->trackCount,
                        'thumbnail_url' => $summary->thumbnailUrl,
                        'author' => $summary->author,
                        'last_synced_at' => now(),
                    ]
                );

                $playlistData = $this->client->playlist(
                    $account->cookie,
                    $summary->id,
                    $summary->trackCount
                );

                $playlist->update([
                    'duration' => $playlistData->duration,
                ]);

                $playlist->tracks()->delete();

                foreach ($playlistData->tracks as $position => $track) {
                    $playlist->tracks()->create([
                        'youtube_video_id' => $track->videoId,
                        'title' => $track->title,
                        'artists' => $track->artists,
                        'album' => $track->album,
                        'duration' => $track->duration,
                        'duration_seconds' => $track->durationSeconds,
                        'thumbnail_url' => $track->thumbnailUrl,
                        'is_explicit' => $track->isExplicit,
                        'is_available' => $track->isAvailable,
                        'position' => $position,
                    ]);
                }

                return $playlist;
            });

            $onProgress?->($index + 1, $total, $playlist);
        }
    }
}
```

This is a behavioral change from one transaction wrapping the whole sync to one transaction per playlist — each playlist's data is durable as soon as it finishes, and progress genuinely reflects committed work.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/Actions/SyncPlaylistsFromYouTubeMusicActionTest.php --compact
```

Expected: PASS (3 tests).

- [ ] **Step 5: Run the existing playlist controller tests to check for regressions**

```bash
php artisan test tests/Feature/Controllers/PlaylistControllerTest.php --compact
```

Expected: still passes (the closure parameter is optional and additive).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/SyncPlaylistsFromYouTubeMusicAction.php tests/Unit/Actions/SyncPlaylistsFromYouTubeMusicActionTest.php
git commit -m "feat: report per-playlist progress from the sync action"
```

---

### Task 5: `YouTubeMusicSyncUpdated` broadcast event + channel authorization

**Files:**
- Create: `app/Events/YouTubeMusicSyncUpdated.php`
- Create: `routes/channels.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Events/YouTubeMusicSyncUpdatedTest.php`

**Interfaces:**
- Consumes: `YouTubeMusicSyncData::fromModel()` (Task 3), `YouTubeMusicSync::isOwnedBy()` (Task 2).
- Produces: `new YouTubeMusicSyncUpdated(YouTubeMusicSync $sync)`, broadcasting as `sync.updated` on `PrivateChannel('youtube-music-sync.{id}')`. Consumed by `SyncYouTubeMusicLibrary` (Task 6) via the global `broadcast()` helper.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Events/YouTubeMusicSyncUpdatedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Models\YouTubeMusicSync;
use Illuminate\Broadcasting\PrivateChannel;

it('broadcasts on a private channel scoped to the sync', function (): void {
    $sync = YouTubeMusicSync::factory()->create();

    $event = new YouTubeMusicSyncUpdated($sync);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-youtube-music-sync.'.$sync->id)
        ->and($event->broadcastAs())->toBe('sync.updated');
});

it('broadcasts the sync as data', function (): void {
    $sync = YouTubeMusicSync::factory()->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'synced_playlists' => 2,
        'total_playlists' => 5,
    ]);

    $payload = (new YouTubeMusicSyncUpdated($sync))->broadcastWith();

    expect($payload)->toMatchArray([
        'id' => $sync->id,
        'status' => 'syncing',
        'totalPlaylists' => 5,
        'syncedPlaylists' => 2,
    ]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Unit/Events/YouTubeMusicSyncUpdatedTest.php --compact
```

Expected: FAIL — class `App\Events\YouTubeMusicSyncUpdated` not found.

- [ ] **Step 3: Create the event**

Create `app/Events/YouTubeMusicSyncUpdated.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\YouTubeMusicSyncData;
use App\Models\YouTubeMusicSync;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class YouTubeMusicSyncUpdated implements ShouldBroadcastNow
{
    public function __construct(public readonly YouTubeMusicSync $sync) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('youtube-music-sync.'.$this->sync->id)];
    }

    public function broadcastAs(): string
    {
        return 'sync.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return YouTubeMusicSyncData::fromModel($this->sync)->toArray();
    }
}
```

`ShouldBroadcastNow` (rather than `ShouldBroadcast`) is used deliberately: this event is always fired from inside a job that is already on the queue, so there is no reason to queue the broadcast itself a second time.

- [ ] **Step 4: Register the channel and wire up broadcasting routes**

Create `routes/channels.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('youtube-music-sync.{syncId}', function (User $user, string $syncId): bool {
    $sync = YouTubeMusicSync::query()->find($syncId);

    return $sync !== null && $sync->isOwnedBy($user);
});
```

In `bootstrap/app.php`, add the `channels` argument to `withRouting()`:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
```

This also registers the `/broadcasting/auth` route automatically (`Broadcast::routes()`), which the frontend client will call in Task 10.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Unit/Events/YouTubeMusicSyncUpdatedTest.php --compact
```

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Events/YouTubeMusicSyncUpdated.php routes/channels.php bootstrap/app.php tests/Unit/Events/YouTubeMusicSyncUpdatedTest.php
git commit -m "feat: broadcast sync progress on a private Mercure channel"
```

---

### Task 6: `SyncYouTubeMusicLibrary` job

**Files:**
- Create: `app/Jobs/SyncYouTubeMusicLibrary.php`
- Test: `tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`

**Interfaces:**
- Consumes: `SyncPlaylistsFromYouTubeMusicAction::handle()` (Task 4), `YouTubeMusicSyncUpdated` (Task 5), `YouTubeMusicSync` (Task 2).
- Produces: `SyncYouTubeMusicLibrary::dispatch(string $syncId, string $youTubeMusicAccountId)`. Consumed by `StartYouTubeMusicSync` (Task 7).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeYouTubeMusicClient;

it('syncs the library and marks the sync completed', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2', title: 'Gaming');

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    $sync->refresh();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Completed)
        ->and($sync->total_playlists)->toBe(2)
        ->and($sync->synced_playlists)->toBe(2)
        ->and($sync->started_at)->not->toBeNull()
        ->and($sync->finished_at)->not->toBeNull()
        ->and(Playlist::query()->count())->toBe(2);

    // Syncing, 0/2, PL1 done (1/2), PL2 done (2/2), Completed.
    Event::assertDispatchedTimes(YouTubeMusicSyncUpdated::class, 5);
});

it('marks the sync failed when YouTube Music cannot be reached', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    $sync->refresh();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($sync->error_message)->not->toBeNull()
        ->and($sync->finished_at)->not->toBeNull();

    // Syncing, Failed.
    Event::assertDispatchedTimes(YouTubeMusicSyncUpdated::class, 2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php --compact
```

Expected: FAIL — class `App\Jobs\SyncYouTubeMusicLibrary` not found.

- [ ] **Step 3: Create the job**

Create `app/Jobs/SyncYouTubeMusicLibrary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Exceptions\YouTubeMusicException;
use App\Models\Playlist;
use App\Models\YouTubeMusicSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncYouTubeMusicLibrary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A bad or expired cookie will not start working on retry, so failing
     * fast (and reporting it) beats silently retrying a doomed job.
     */
    public int $tries = 1;

    public function __construct(
        public readonly string $syncId,
        public readonly string $youTubeMusicAccountId,
    ) {}

    /**
     * A queue-level backstop: `StartYouTubeMusicSync` already avoids creating
     * a second sync row for an account that has one in flight, but if a race
     * ever slips through, only one job per account should actually run.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->youTubeMusicAccountId))->dontRelease(),
        ];
    }

    public function handle(SyncPlaylistsFromYouTubeMusicAction $action): void
    {
        $sync = YouTubeMusicSync::query()->with('youtubeMusicAccount')->findOrFail($this->syncId);

        $sync->update([
            'status' => YouTubeMusicSyncStatus::Syncing,
            'started_at' => now(),
        ]);
        broadcast(new YouTubeMusicSyncUpdated($sync));

        try {
            $action->handle(
                $sync->youtubeMusicAccount,
                function (int $synced, int $total, ?Playlist $playlist) use ($sync): void {
                    $sync->update([
                        'total_playlists' => $total,
                        'synced_playlists' => $synced,
                        'current_playlist_title' => $playlist?->title,
                    ]);
                    broadcast(new YouTubeMusicSyncUpdated($sync));
                },
            );
        } catch (YouTubeMusicException $exception) {
            $sync->update([
                'status' => YouTubeMusicSyncStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            broadcast(new YouTubeMusicSyncUpdated($sync));

            return;
        }

        $sync->update([
            'status' => YouTubeMusicSyncStatus::Completed,
            'finished_at' => now(),
        ]);
        broadcast(new YouTubeMusicSyncUpdated($sync));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php --compact
```

Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/SyncYouTubeMusicLibrary.php tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php
git commit -m "feat: add the SyncYouTubeMusicLibrary job"
```

---

### Task 7: `StartYouTubeMusicSync` action

**Files:**
- Create: `app/Actions/StartYouTubeMusicSync.php`
- Test: `tests/Unit/Actions/StartYouTubeMusicSyncTest.php`

**Interfaces:**
- Consumes: `SyncYouTubeMusicLibrary::dispatch()` (Task 6), `YouTubeMusicSync` + `YouTubeMusicSyncStatus` (Task 2).
- Produces: `StartYouTubeMusicSync::handle(YouTubeMusicAccount $account): YouTubeMusicSync`. Consumed by `YouTubeMusicConnectionController` (Task 8) and `PlaylistController` (Task 9).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Actions/StartYouTubeMusicSyncTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\StartYouTubeMusicSync;
use App\Enums\YouTubeMusicSyncStatus;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Queue;

it('creates a pending sync and dispatches the job', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();

    $sync = resolve(StartYouTubeMusicSync::class)->handle($account);

    expect($sync->youtube_music_account_id)->toBe($account->id)
        ->and($sync->status)->toBe(YouTubeMusicSyncStatus::Pending);

    Queue::assertPushed(
        SyncYouTubeMusicLibrary::class,
        fn (SyncYouTubeMusicLibrary $job): bool => $job->syncId === $sync->id
            && $job->youTubeMusicAccountId === $account->id,
    );
});

it('reuses an active sync instead of starting a second one', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $existing = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
    ]);

    $sync = resolve(StartYouTubeMusicSync::class)->handle($account);

    expect($sync->id)->toBe($existing->id)
        ->and(YouTubeMusicSync::query()->count())->toBe(1);

    Queue::assertNotPushed(SyncYouTubeMusicLibrary::class);
});

it('starts a new sync once the previous one finished', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Completed,
    ]);

    resolve(StartYouTubeMusicSync::class)->handle($account);

    expect(YouTubeMusicSync::query()->count())->toBe(2);
    Queue::assertPushed(SyncYouTubeMusicLibrary::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/Actions/StartYouTubeMusicSyncTest.php --compact
```

Expected: FAIL — class `App\Actions\StartYouTubeMusicSync` not found.

- [ ] **Step 3: Create the action**

Create `app/Actions/StartYouTubeMusicSync.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\YouTubeMusicSyncStatus;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Cache;

final readonly class StartYouTubeMusicSync
{
    /**
     * Reuses an active (pending or syncing) sync for the account if one
     * exists, otherwise creates one and dispatches the job. The check and
     * the creation happen under a lock so two near-simultaneous requests
     * (e.g. two tabs) can never both create a sync for the same account.
     */
    public function handle(YouTubeMusicAccount $account): YouTubeMusicSync
    {
        return Cache::lock('youtube-music-sync-start:'.$account->id, 10)
            ->block(5, function () use ($account): YouTubeMusicSync {
                $existing = YouTubeMusicSync::query()
                    ->where('youtube_music_account_id', $account->id)
                    ->whereIn('status', [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing])
                    ->latest('created_at')
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $sync = YouTubeMusicSync::query()->create([
                    'youtube_music_account_id' => $account->id,
                    'status' => YouTubeMusicSyncStatus::Pending,
                ]);

                SyncYouTubeMusicLibrary::dispatch($sync->id, $account->id);

                return $sync;
            });
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/Actions/StartYouTubeMusicSyncTest.php --compact
```

Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/StartYouTubeMusicSync.php tests/Unit/Actions/StartYouTubeMusicSyncTest.php
git commit -m "feat: add StartYouTubeMusicSync, the idempotent entry point for syncing"
```

---

### Task 8: Connection controller — start a sync on connect, render the sync page

**Files:**
- Modify: `app/Http/Controllers/YouTubeMusicConnectionController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php`

**Interfaces:**
- Consumes: `StartYouTubeMusicSync::handle()` (Task 7), `YouTubeMusicSyncData::fromModel()` (Task 3).
- Produces: route `youtube-music-connection.sync` (`GET /youtube-music/sync/{sync}`), rendering Inertia page `youtube-music-connection/Sync` with prop `sync: App.Data.YouTubeMusicSyncData`. Consumed by the frontend in Task 11.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php`, replace the `'connects an account and lands on the playlists'` test and add two new ones. Replace:

```php
it('connects an account and lands on the playlists', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('youtube-music-connection.create')
        ->post(route('youtube-music-connection.store'), [
            'cookie' => YouTubeMusicAccountFactory::cookie(),
        ]);

    $response->assertRedirectToRoute('playlist.index');

    expect($user->youTubeMusicAccount()->first())->not->toBeNull();
});
```

with:

```php
it('connects an account and starts a sync', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('youtube-music-connection.create')
        ->post(route('youtube-music-connection.store'), [
            'cookie' => YouTubeMusicAccountFactory::cookie(),
        ]);

    $account = $user->youTubeMusicAccount()->first();
    expect($account)->not->toBeNull();

    $sync = YouTubeMusicSync::query()->where('youtube_music_account_id', $account->id)->firstOrFail();

    $response->assertRedirectToRoute('youtube-music-connection.sync', $sync);
});

it('renders the sync page', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'total_playlists' => 4,
        'synced_playlists' => 2,
        'current_playlist_title' => 'Deep Focus',
    ]);

    $response = $this->actingAs($user)->get(route('youtube-music-connection.sync', $sync));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('youtube-music-connection/Sync')
            ->where('sync.id', $sync->id)
            ->where('sync.status', 'syncing')
            ->where('sync.totalPlaylists', 4)
            ->where('sync.syncedPlaylists', 2)
            ->where('sync.currentPlaylistTitle', 'Deep Focus'));
});

it('refuses to show another user\'s sync', function (): void {
    $owner = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($owner)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('youtube-music-connection.sync', $sync));

    $response->assertNotFound();
});
```

Add these imports at the top of the file, alongside the existing ones:

```php
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicSync;
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php --compact
```

Expected: FAIL — route `youtube-music-connection.sync` does not exist yet, and the `store` redirect target has changed.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `auth`/`verified` group, right after the existing `youtube-music` routes:

```php
    Route::get('youtube-music/sync/{sync}', [YouTubeMusicConnectionController::class, 'sync'])
        ->name('youtube-music-connection.sync');
```

- [ ] **Step 4: Update the controller**

Replace `app/Http/Controllers/YouTubeMusicConnectionController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConnectYouTubeMusicAccount;
use App\Actions\DisconnectYouTubeMusicAccount;
use App\Actions\StartYouTubeMusicSync;
use App\Data\YouTubeMusicSyncData;
use App\Exceptions\YouTubeMusicException;
use App\Http\Requests\CreateYouTubeMusicConnectionRequest;
use App\Models\User;
use App\Models\YouTubeMusicSync;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class YouTubeMusicConnectionController
{
    public function create(#[CurrentUser] User $user): Response
    {
        return Inertia::render('youtube-music-connection/Create', [
            'account' => $user->youTubeMusicAccount()->first()?->only('account_name', 'last_verified_at'),
        ]);
    }

    public function store(
        CreateYouTubeMusicConnectionRequest $request,
        #[CurrentUser] User $user,
        ConnectYouTubeMusicAccount $action,
        StartYouTubeMusicSync $startSync,
    ): RedirectResponse {
        try {
            $account = $action->handle($user, $request->string('cookie')->value());
        } catch (YouTubeMusicException) {
            return back()->withErrors([
                'cookie' => 'YouTube Music rejected this cookie. Make sure you are signed in, and copy the header again.',
            ]);
        }

        $sync = $startSync->handle($account);

        return to_route('youtube-music-connection.sync', $sync);
    }

    public function sync(YouTubeMusicSync $sync, #[CurrentUser] User $user): Response
    {
        abort_unless($sync->isOwnedBy($user), 404);

        return Inertia::render('youtube-music-connection/Sync', [
            'sync' => YouTubeMusicSyncData::fromModel($sync),
        ]);
    }

    public function destroy(#[CurrentUser] User $user, DisconnectYouTubeMusicAccount $action): RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account !== null) {
            $action->handle($account);
        }

        return to_route('youtube-music-connection.create');
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php --compact
```

Expected: PASS.

- [ ] **Step 6: Regenerate Wayfinder actions/routes and format**

```bash
php artisan wayfinder:generate
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/YouTubeMusicConnectionController.php routes/web.php \
  tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php \
  resources/js/actions resources/js/routes
git commit -m "feat: start a sync on connect and render a dedicated sync page"
```

---

### Task 9: Playlist controller — sync in the background, expose active sync state

**Files:**
- Modify: `app/Http/Controllers/PlaylistController.php`
- Modify: `app/Models/Playlist.php`
- Create: `database/factories/PlaylistFactory.php`
- Modify: `tests/Feature/Controllers/PlaylistControllerTest.php`

**Interfaces:**
- Consumes: `StartYouTubeMusicSync::handle()` (Task 7), `YouTubeMusicSyncData::fromModel()` (Task 3).
- Produces: `playlist/Index` Inertia prop `activeSync: App.Data.YouTubeMusicSyncData | null`, consumed by the frontend in Task 12.

- [ ] **Step 1: Update the tests**

In `tests/Feature/Controllers/PlaylistControllerTest.php`, replace the `'lists the playlists of the connected account'` test with a version that warms up the first sync before asserting on the rendered page (the first-ever visit now redirects to the sync page instead of rendering the list directly):

```php
it('lists the playlists of the connected account', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create(['account_name' => 'Mishaa']);
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2', title: 'Gaming');

    // First visit: nothing synced yet, redirects to the blocking sync page.
    $this->actingAs($user)->get(route('playlist.index'))
        ->assertRedirectToRoute('youtube-music-connection.sync', YouTubeMusicSync::query()->firstOrFail());

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Index')
            ->where('accountName', 'Mishaa')
            ->where('activeSync', null)
            ->has('playlists', 2)
            ->where('playlists.0.title', 'Deep Focus'));
});
```

Replace `'sends the user back to reconnect when the cookie stopped working'` (that behavior moved to the sync page) with:

```php
it('redirects to the sync page and fails the sync when the cookie stopped working', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertRedirectToRoute('youtube-music-connection.sync', YouTubeMusicSync::query()->firstOrFail());
    expect(YouTubeMusicSync::query()->firstOrFail()->status)->toBe(YouTubeMusicSyncStatus::Failed);
});
```

Add a new test for the background-refresh path:

```php
it('refreshes stale playlists in the background instead of blocking', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    Playlist::factory()->for($account, 'youtubeMusicAccount')->create([
        'last_synced_at' => now()->subDays(2),
    ]);
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Index')
            ->has('activeSync')
            ->where('activeSync.status', 'completed'));
});
```

Add these imports at the top of the file:

```php
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicSync;
```

This last test needs `Playlist::factory()`, which does not exist yet — add it in Step 3 below, alongside the controller change.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Controllers/PlaylistControllerTest.php --compact
```

Expected: FAIL — `index()` still syncs synchronously and never redirects to a sync page, and `Playlist::factory()` does not exist yet.

- [ ] **Step 3: Give `Playlist` a factory**

`Playlist` has no `HasFactory` trait or factory today (it has only ever been created through the sync action). Add the trait in `app/Models/Playlist.php`:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Playlist extends Model
{
    /** @use HasFactory<\Database\Factories\PlaylistFactory> */
    use HasFactory;

    use HasUuids;

    // ...unchanged from here down.
```

Create `database/factories/PlaylistFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Playlist>
 */
final class PlaylistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_music_account_id' => YouTubeMusicAccount::factory(),
            'youtube_playlist_id' => fake()->uuid(),
            'title' => fake()->words(3, true),
            'last_synced_at' => now(),
        ];
    }
}
```

- [ ] **Step 4: Update the controller**

Replace `app/Http/Controllers/PlaylistController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartYouTubeMusicSync;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Data\TrackData;
use App\Data\YouTubeMusicSyncData;
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicSync;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PlaylistController
{
    public function index(#[CurrentUser] User $user, StartYouTubeMusicSync $startSync): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        if ($account->playlists()->doesntExist()) {
            $sync = $startSync->handle($account);

            return to_route('youtube-music-connection.sync', $sync);
        }

        $activeSync = $account->playlists()->max('last_synced_at') < now()->subHour()
            ? $startSync->handle($account)
            : YouTubeMusicSync::query()
                ->where('youtube_music_account_id', $account->id)
                ->whereIn('status', [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing])
                ->latest('created_at')
                ->first();

        $playlists = $account->playlists()->get()->map(
            fn (Playlist $playlist) => PlaylistSummaryData::from([
                'id' => $playlist->youtube_playlist_id,
                'title' => $playlist->title,
                'description' => $playlist->description,
                'trackCount' => $playlist->track_count,
                'thumbnailUrl' => $playlist->thumbnail_url,
                'author' => $playlist->author,
            ])
        );

        return Inertia::render('playlist/Index', [
            'accountName' => $account->account_name,
            'playlists' => $playlists,
            'activeSync' => $activeSync !== null ? YouTubeMusicSyncData::fromModel($activeSync) : null,
        ]);
    }

    public function show(string $playlistId, #[CurrentUser] User $user): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        $playlist = $account->playlists()
            ->where('youtube_playlist_id', $playlistId)
            ->with('tracks')
            ->first();

        if ($playlist === null) {
            return to_route('playlist.index');
        }

        $summary = PlaylistSummaryData::from([
            'id' => $playlist->youtube_playlist_id,
            'title' => $playlist->title,
            'description' => $playlist->description,
            'trackCount' => $playlist->track_count,
            'thumbnailUrl' => $playlist->thumbnail_url,
            'author' => $playlist->author,
        ]);

        $tracks = $playlist->tracks->map(fn ($track) => TrackData::from([
            'videoId' => $track->youtube_video_id,
            'title' => $track->title,
            'artists' => $track->artists,
            'album' => $track->album,
            'duration' => $track->duration,
            'durationSeconds' => $track->duration_seconds,
            'thumbnailUrl' => $track->thumbnail_url,
            'isExplicit' => $track->is_explicit,
            'isAvailable' => $track->is_available,
        ]));

        $playlistData = PlaylistData::from([
            'id' => $playlist->youtube_playlist_id,
            'title' => $playlist->title,
            'description' => $playlist->description,
            'trackCount' => $playlist->track_count,
            'duration' => $playlist->duration,
            'thumbnailUrl' => $playlist->thumbnail_url,
            'author' => $playlist->author,
            'tracks' => $tracks->toArray(),
        ]);

        return Inertia::render('playlist/Show', [
            'playlistId' => $playlistId,
            'summary' => $summary,
            'playlist' => $playlistData,
        ]);
    }
}
```

Note this removes the `expired()` helper and the `YouTubeMusicException` import — cookie failures now surface on the sync page (Task 11) instead of being caught synchronously here.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Controllers/PlaylistControllerTest.php --compact
```

Expected: PASS.

- [ ] **Step 6: Run the full suite for regressions**

```bash
php artisan test --compact
```

Expected: PASS across the board.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PlaylistController.php tests/Feature/Controllers/PlaylistControllerTest.php \
  database/factories/PlaylistFactory.php
git commit -m "feat: sync stale playlists in the background instead of blocking the request"
```

---

### Task 10: Mercure `EventSource` client

**Files:**
- Create: `resources/js/lib/mercure.ts`

**Interfaces:**
- Produces: `subscribeToPrivateChannel(channel: string, onMessage: (message: MercureMessage) => void): () => void` — fetches `/broadcasting/auth`, opens an `EventSource` on the returned topic, invokes `onMessage` for every parsed event, and returns an unsubscribe function. Consumed by `Sync.vue` (Task 11) and `playlist/Index.vue` (Task 12).

This project has no JavaScript test runner configured yet (no `vitest.config.ts`, no existing `*.test.ts` files), so introducing one is out of scope here. Correctness is verified manually against the real Mercure hub in Task 13.

- [ ] **Step 1: Create the client**

Create `resources/js/lib/mercure.ts`:

```ts
type MercureMessage = {
    channels?: string[];
    event: string;
    payload: unknown;
};

/**
 * Subscribes to a Laravel private broadcasting channel over Mercure using
 * the native `EventSource` API. There is no `laravel-echo` dependency here:
 * its Mercure connector is not published yet (merged, but no npm release as
 * of this writing), so this reimplements the small slice of the protocol
 * this app needs — auth, one topic, JSON messages. No presence, whispers, or
 * end-to-end encryption, and no refresh of the auth cookie before it expires
 * (`subscribe_expiration` is 15 minutes server-side, which comfortably
 * covers a sync).
 *
 * Returns an unsubscribe function.
 */
export function subscribeToPrivateChannel(
    channel: string,
    onMessage: (message: MercureMessage) => void,
): () => void {
    let eventSource: EventSource | null = null;
    let cancelled = false;

    const channelName = `private-${channel}`;

    void fetch('/broadcasting/auth', {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ channel_names: [channelName] }),
    })
        .then((response) => {
            if (!response.ok || cancelled) {
                return null;
            }

            return response.json() as Promise<{ topic_prefix: string }>;
        })
        .then((data) => {
            if (!data || cancelled) {
                return;
            }

            const topic = `${data.topic_prefix}channel/${encodeURIComponent(channelName)}`;

            eventSource = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(topic)}`, {
                withCredentials: true,
            });

            eventSource.onmessage = (event: MessageEvent<string>) => {
                onMessage(JSON.parse(event.data) as MercureMessage);
            };
        });

    return () => {
        cancelled = true;
        eventSource?.close();
    };
}
```

- [ ] **Step 2: Type-check**

```bash
npm run test:types
```

Expected: no new errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/lib/mercure.ts
git commit -m "feat: add a minimal Mercure EventSource client"
```

---

### Task 11: `Sync.vue` — the blocking sync page

**Files:**
- Create: `resources/js/pages/youtube-music-connection/Sync.vue`

**Interfaces:**
- Consumes: prop `sync: App.Data.YouTubeMusicSyncData` (Task 8), `subscribeToPrivateChannel()` (Task 10).

- [ ] **Step 1: Create the page**

Create `resources/js/pages/youtube-music-connection/Sync.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { subscribeToPrivateChannel } from '@/lib/mercure';
import type { BreadcrumbItem } from '@/types';

type Props = {
    sync: App.Data.YouTubeMusicSyncData;
};

const props = defineProps<Props>();

const state = reactive({ ...props.sync });
const completedTitles = ref<string[]>([]);

let unsubscribe: (() => void) | null = null;

function goToPlaylists() {
    router.visit(PlaylistController.index().url);
}

onMounted(() => {
    if (state.status === 'completed') {
        goToPlaylists();
        return;
    }

    if (state.status === 'failed') {
        return;
    }

    unsubscribe = subscribeToPrivateChannel(`youtube-music-sync.${props.sync.id}`, (message) => {
        if (message.event !== 'sync.updated') {
            return;
        }

        const payload = message.payload as App.Data.YouTubeMusicSyncData;

        if (payload.syncedPlaylists > state.syncedPlaylists && state.currentPlaylistTitle) {
            completedTitles.value.push(state.currentPlaylistTitle);
        }

        Object.assign(state, payload);

        if (payload.status === 'completed') {
            setTimeout(goToPlaylists, 600);
        }
    });
});

onUnmounted(() => unsubscribe?.());

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Syncing your library', href: '#' }];

const progress = () =>
    state.totalPlaylists && state.totalPlaylists > 0
        ? Math.round((state.syncedPlaylists / state.totalPlaylists) * 100)
        : 0;
</script>

<template>
    <Head title="Syncing your library" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-xl flex-col items-center gap-8 p-4 py-16 text-center">
            <template v-if="state.status === 'failed'">
                <XCircle class="size-10 text-destructive" />
                <Heading title="The sync failed" :description="state.errorMessage ?? undefined" />
                <div class="flex items-center gap-3">
                    <Button as-child>
                        <Link :href="YouTubeMusicConnectionController.create()">Reconnect</Link>
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="PlaylistController.index()">Retry</Link>
                    </Button>
                </div>
            </template>

            <template v-else>
                <Heading
                    title="Syncing your library"
                    description="This only takes a moment — we're reading your playlists straight from YouTube Music."
                />

                <div class="w-full space-y-2">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-primary transition-all duration-500 ease-out"
                            :style="{ width: `${progress()}%` }"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ state.syncedPlaylists }} / {{ state.totalPlaylists ?? '…' }} playlists
                    </p>
                </div>

                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 -translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                    mode="out-in"
                >
                    <p :key="state.currentPlaylistTitle ?? 'waiting'" class="text-sm font-medium">
                        {{ state.currentPlaylistTitle ? `Syncing “${state.currentPlaylistTitle}”…` : 'Fetching your playlists…' }}
                    </p>
                </Transition>

                <ul v-if="completedTitles.length > 0" class="w-full space-y-1.5 text-left text-sm text-muted-foreground">
                    <TransitionGroup
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-x-1"
                        enter-to-class="opacity-100 translate-x-0"
                    >
                        <li v-for="title in completedTitles" :key="title" class="flex items-center gap-2">
                            <CheckCircle2 class="size-4 shrink-0 text-emerald-600" />
                            <span class="truncate">{{ title }}</span>
                        </li>
                    </TransitionGroup>
                </ul>
            </template>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 2: Type-check**

```bash
npm run test:types
```

Expected: no new errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/youtube-music-connection/Sync.vue
git commit -m "feat: add the blocking sync progress page"
```

---

### Task 12: Non-blocking sync badge on `playlist/Index.vue`

**Files:**
- Modify: `resources/js/pages/playlist/Index.vue`

**Interfaces:**
- Consumes: prop `activeSync: App.Data.YouTubeMusicSyncData | null` (Task 9), `subscribeToPrivateChannel()` (Task 10).

- [ ] **Step 1: Update the page**

In `resources/js/pages/playlist/Index.vue`, update the `<script setup>` block:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ListMusic, LoaderCircle, Settings2 } from 'lucide-vue-next';
import { onMounted, onUnmounted, reactive } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { subscribeToPrivateChannel } from '@/lib/mercure';
import type { BreadcrumbItem } from '@/types';

type Props = {
    accountName: string;
    playlists: App.Data.PlaylistSummaryData[];
    activeSync: App.Data.YouTubeMusicSyncData | null;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Playlists', href: PlaylistController.index() },
];

const sync = props.activeSync ? reactive({ ...props.activeSync, visible: true }) : null;

let unsubscribe: (() => void) | null = null;

onMounted(() => {
    if (!sync) {
        return;
    }

    unsubscribe = subscribeToPrivateChannel(`youtube-music-sync.${sync.id}`, (message) => {
        if (message.event !== 'sync.updated') {
            return;
        }

        const payload = message.payload as App.Data.YouTubeMusicSyncData;
        Object.assign(sync, payload);

        if (payload.status === 'completed') {
            sync.visible = false;
            router.reload({ only: ['playlists', 'accountName'] });
        } else if (payload.status === 'failed') {
            sync.visible = false;
        }
    });
});

onUnmounted(() => unsubscribe?.());
</script>
```

Then, right after the opening `<Heading ... />` in the template (inside the `flex items-end justify-between gap-4` container, before the "Connection" `<Button>`), add:

```vue
                <div
                    v-if="sync?.visible"
                    class="flex items-center gap-2 rounded-full border bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
                >
                    <LoaderCircle class="size-3.5 animate-spin" />
                    Syncing… {{ sync.syncedPlaylists }}/{{ sync.totalPlaylists ?? '…' }}
                </div>
```

- [ ] **Step 2: Type-check**

```bash
npm run test:types
```

Expected: no new errors.

- [ ] **Step 3: Manually verify props still render (existing feature tests already cover this)**

```bash
php artisan test tests/Feature/Controllers/PlaylistControllerTest.php --compact
```

Expected: PASS (already updated in Task 9).

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/playlist/Index.vue
git commit -m "feat: show a non-blocking sync badge on the playlist index"
```

---

### Task 13: Deploy — Cloud environment variables and manual verification

**Files:** none (operational task).

- [ ] **Step 1: Set the production secret**

Using the `cloud` CLI, set a production-only secret (different from the local `.env` value) on the `sonder` app's `production` environment:

```bash
cloud environment:variables production --app sonder
```

(Follow the interactive prompt, or check `cloud environment:variables --help` for a non-interactive file-based alternative — this replaces all variables from a file, so fetch the existing ones first with `cloud environment:get production --app sonder --json --show-sensitive --fields environmentVariables` and add `MERCURE_JWT_SECRET` and `BROADCAST_CONNECTION=mercure` to that list before applying.)

- [ ] **Step 2: Run PHP checks**

```bash
composer install
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Expected: PASS.

- [ ] **Step 3: Run frontend checks**

```bash
npm run test:types
npm run test:lint
npm run build
```

Expected: PASS.

- [ ] **Step 4: Manually verify the full flow locally**

```bash
composer run dev
```

Then, in a browser: disconnect any existing YouTube Music connection, reconnect with a real cookie, and confirm:
- The page redirects to `/youtube-music/sync/{id}` immediately (no 20s freeze).
- The progress bar and playlist list animate as playlists sync.
- The page redirects to `/playlists` once complete.
- Revisiting `/playlists` more than an hour later (or manually setting a `last_synced_at` in the past) shows the small "Syncing…" badge instead of blocking.

- [ ] **Step 5: Deploy**

```bash
cloud deploy --app sonder --environment production
```

Confirm the deployment succeeds and the production Mercure hub comes up (check `cloud environment:logs production --app sonder` for the Caddy `mercure` directive being active, or repeat the manual verification from Step 4 against the production URL).

- [ ] **Step 6: Commit any leftover changes**

```bash
git status
```

If everything from prior tasks was already committed, this task produces no diff — nothing to commit.
