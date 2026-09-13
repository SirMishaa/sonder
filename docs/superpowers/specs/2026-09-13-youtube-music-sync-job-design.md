# YouTube Music sync: background job + real-time progress UI

## Problem

Connecting a YouTube Music account, or loading `/playlists` with stale data
(>1h old), currently runs `SyncPlaylistsFromYouTubeMusicAction` synchronously
inside the HTTP request. For a real library this fetches every playlist and
every track sequentially, blocking the request for 20-25s and sometimes past
30s (risking a gateway timeout on Laravel Cloud/Octane).

## Goals

- Move the sync off the request/response cycle and onto the queue.
- Give the user a clear, animated view of sync progress instead of a frozen
  page.
- Work identically in local dev and on Laravel Cloud, both running Octane
  with the FrankenPHP server and its built-in Mercure hub.
- Prevent two overlapping syncs for the same account.

## Non-goals

- Presence channels, end-to-end encrypted channels, or whispers (Mercure
  protocol features we don't need here).
- Migrating to the official `laravel-echo` Mercure connector — it isn't
  published yet (merged into the `2.x` branch, no npm release as of this
  writing). We write a minimal native `EventSource` client instead.
- Auto-provisioning of Mercure secrets by Laravel Cloud — the `cloud` CLI has
  no Mercure resource today, so the app owns its own `MERCURE_JWT_SECRET`
  and Caddy directive.

## Background: Mercure availability

Laravel's native `mercure` broadcast driver
([laravel/framework#61474](https://github.com/laravel/framework/pull/61474))
is merged into the `13.x` branch but not yet in a tagged release (latest tag
is `v13.31.0`, released before the merge). Per explicit approval, we pin
`laravel/framework` to `13.x-dev` to use it now rather than hand-roll a
Mercure integration we'd have to rip out later.

`symfony/mercure` is a *suggested*, not required, dependency of
`illuminate/broadcasting` — it must be required explicitly
(`symfony/mercure:^0.8`), since the driver's `FrankenPhpHub`, `Hub`,
`Update`, and JWT factory classes live there.

FrankenPHP's built-in Mercure hub still needs a signing secret (there is no
truly zero-config mode): the `mercure { publisher_jwt ...; subscriber_jwt
...; }` Caddy directive must share a secret with Laravel's broadcasting
config. The app's `Caddyfile` already has a `{$CADDY_SERVER_EXTRA_DIRECTIVES}`
placeholder with a comment reading "Mercure configuration is injected
here...". That placeholder, and every other `{$CADDY_SERVER_*}` one in the
file, is populated by **Laravel Octane's own `StartFrankenPhpCommand`**
(`vendor/laravel/octane/src/Commands/StartFrankenPhpCommand.php`), not by
Laravel Cloud magic: its `buildMercureConfig()` method reads a `'mercure'`
key from `config/octane.php` and renders it into the `CADDY_SERVER_EXTRA_DIRECTIVES`
env var it passes to the Caddy process. Both local dev (`octane:start
--server=frankenphp --caddyfile=Caddyfile`, per `composer.json`'s `dev`
script) and Cloud (which runs the app via Octane, per the `usesOctane: true`
environment flag and the presence of this exact Octane-authored Caddyfile)
go through this same command, so a single `config/octane.php` change enables
Mercure identically in both places — no manual `CADDY_SERVER_EXTRA_DIRECTIVES`
env var needed.

## Design

### Dependencies & configuration

- `composer.json`: `"laravel/framework": "13.x-dev"`, add
  `"symfony/mercure": "^0.8"`.
- New `config/broadcasting.php` with a `mercure` connection: no `url` (uses
  the FrankenPHP built-in hub via `mercure_publish()`), `secret =>
  env('MERCURE_JWT_SECRET')`, `subscribe_expiration => 15` (minutes — margin
  for slow syncs on large libraries).
- `config/octane.php`: add a `'mercure'` key —
  `['publisher_jwt' => env('MERCURE_JWT_SECRET'), 'subscriber_jwt' =>
  env('MERCURE_JWT_SECRET')]` — so Octane's FrankenPHP server command emits
  the matching Caddy `mercure {...}` directive. No `anonymous` directive: we
  only use private, authenticated channels.
- `.env` / `.env.example` additions:
  - `BROADCAST_CONNECTION=mercure`
  - `MERCURE_JWT_SECRET=` (32+ byte secret, one value shared by both the
    Caddy hub and Laravel's broadcaster, since the hub must verify tokens
    Laravel signs)
- `routes/channels.php` (new file): authorize
  `youtube-music-sync.{syncId}` for the owning user only.

### Data model

New migration `create_youtube_music_syncs_table`:

| column | type | notes |
|---|---|---|
| `id` | uuid, pk | |
| `youtube_music_account_id` | uuid, fk → `youtube_music_accounts` | cascade delete |
| `status` | string | backed by `App\Enums\YouTubeMusicSyncStatus`: `Pending`, `Syncing`, `Completed`, `Failed` |
| `total_playlists` | nullable int | set once summaries are fetched |
| `synced_playlists` | int, default 0 | |
| `current_playlist_title` | nullable string | |
| `error_message` | nullable string | |
| `started_at` | nullable timestamp | |
| `finished_at` | nullable timestamp | |
| timestamps | | |

New model `App\Models\YouTubeMusicSync` (`belongsTo` `YouTubeMusicAccount`),
new enum `App\Enums\YouTubeMusicSyncStatus`.

### Sync logic

- `SyncPlaylistsFromYouTubeMusicAction::handle()` gains an optional
  `?Closure $onPlaylistSynced` parameter, invoked after each playlist is
  fully upserted with `(Playlist $playlist, int $index, int $total)`. The
  action stays queue-agnostic and unit-testable on its own. Each playlist is
  now wrapped in its own `DB::transaction()` instead of one transaction for
  the whole sync, so progress is durable and a late failure doesn't discard
  already-synced playlists.
- New action `StartYouTubeMusicSync`: given a `YouTubeMusicAccount`, reuses
  an existing `Pending`/`Syncing` row for that account if one exists
  (idempotent from the caller's perspective), otherwise creates a `Pending`
  `YouTubeMusicSync` and dispatches the job. Returns the `YouTubeMusicSync`.
- New job `App\Jobs\SyncYouTubeMusicLibrary` (`ShouldQueue`, `$tries = 1` —
  a bad cookie won't fix itself on retry). `middleware()` returns
  `[(new WithoutOverlapping($account->id))->releaseAfter(0)]` as a
  queue-level backstop alongside the application-level reuse in
  `StartYouTubeMusicSync`. `handle()`:
  1. Marks the sync `Syncing`, sets `started_at`, broadcasts.
  2. Calls the sync action with a closure that updates
     `synced_playlists`/`current_playlist_title` and broadcasts after each
     playlist.
  3. On success: marks `Completed`, `finished_at`, broadcasts.
  4. On `YouTubeMusicException`: marks `Failed` with `error_message`,
     `finished_at`, broadcasts — does not rethrow (so the queue doesn't
     treat it as a failed job worth retrying).
- New event `App\Events\YouTubeMusicSyncUpdated` implementing
  `ShouldBroadcastNow` (not `ShouldBroadcast` — we're already inside a
  queued job, no need to requeue the broadcast itself). Broadcasts on
  `PrivateChannel('youtube-music-sync.'.$sync->id)` as `sync.updated` with
  `{status, totalPlaylists, syncedPlaylists, currentPlaylistTitle,
  errorMessage}`.

### Controllers

- `YouTubeMusicConnectionController::store()`: after
  `ConnectYouTubeMusicAccount::handle()` succeeds, call
  `StartYouTubeMusicSync::handle($account)` and redirect to the new
  `youtube-music-connection.sync` route with the sync id.
- New route `GET youtube-music/sync/{sync}` →
  `YouTubeMusicConnectionController::sync()` (or a small dedicated
  controller), rendering `Inertia::render('youtube-music-connection/Sync', [
  'syncId' => ..., 'status' => ..., 'totalPlaylists' => ...,
  'syncedPlaylists' => ..., 'currentPlaylistTitle' => ..., 'errorMessage' =>
  ... ])` — the initial snapshot lets the page render correctly even before
  the first Mercure event arrives (or after a hard refresh).
- `PlaylistController::index()`:
  - No playlists yet for the account → `StartYouTubeMusicSync::handle()` and
    redirect to `youtube-music-connection.sync` (nothing to show yet,
    blocking is the right call).
  - Playlists exist but stale (`max(last_synced_at) < now()->subHour()`) →
    `StartYouTubeMusicSync::handle()` fire-and-forget, then render
    `playlist/Index` immediately with the existing (stale) data plus an
    `activeSyncId` prop (the dispatched sync's id, or an already-running
    one's id if a concurrent sync exists).
  - Otherwise → render normally, `activeSyncId: null`.

### Frontend

- `resources/js/lib/mercure.ts`: minimal client —
  `POST /broadcasting/auth` with `channel_names: [channel]` (credentials
  included) to mint the httpOnly cookie, read `topic_prefix` from the JSON
  response, then open `new EventSource('/.well-known/mercure?topic=' +
  encodeURIComponent(topicPrefix + 'channel/' + encodeURIComponent(channel)),
  { withCredentials: true })`. Parses `{channels, event, payload}` JSON
  messages and invokes a callback. No presence/whisper/encryption, no
  auto-refresh of the auth cookie (15 minute expiry is ample margin for a
  sync; revisit if that stops being true).
- `resources/js/pages/youtube-music-connection/Sync.vue`: blocking,
  full-page sync view. Shows an overall progress bar (`syncedPlaylists /
  totalPlaylists`), the current playlist title transitioning in/out
  (`<Transition>`), and a running, auto-appending list of completed playlist
  titles with a checkmark. Subscribes to the Mercure channel on mount using
  `syncId`, seeds local state from the initial Inertia props. On `Completed`,
  briefly shows a success state then `router.visit(PlaylistController.index())`.
  On `Failed`, shows the error message with "Reconnect" (→
  `youtube-music-connection.create`) and "Retry" actions.
- `resources/js/pages/playlist/Index.vue`: when `activeSyncId` is present,
  shows a small non-blocking badge near the heading ("Syncing… x/y
  playlists") driven by the same Mercure subscription; on `Completed` it
  calls `router.reload({ only: ['playlists', 'accountName'] })` and hides
  itself.

### Testing

- `SyncPlaylistsFromYouTubeMusicAction`: existing behavior plus the
  `onPlaylistSynced` callback firing with correct arguments; per-playlist
  transaction behavior.
- `SyncYouTubeMusicLibrary` job: upserts playlists/tracks correctly, final
  sync status/timestamps on success and on `YouTubeMusicException`,
  `YouTubeMusicSyncUpdated` broadcast the expected number of times
  (`Event::fake`).
- `StartYouTubeMusicSync`: creates a new sync when none active, reuses an
  existing `Pending`/`Syncing` one instead of creating a duplicate.
- Controllers: `Queue::fake()` + assert `SyncYouTubeMusicLibrary` pushed;
  connect redirects to the sync page; playlist index redirects to the sync
  page when empty, renders immediately with `activeSyncId` when stale,
  renders normally with `activeSyncId: null` when fresh.
- `routes/channels.php`: only the owning user is authorized for
  `youtube-music-sync.{syncId}`.

### Deployment

The Laravel Cloud app `sonder` (`app-a2bc340a-5f0f-4124-9076-569a3b879b2f`,
environment `production`, `env-a2bc340c-dd3b-4a0e-9048-fe7a829a2491`) already
runs with Octane enabled. Before/with the deploy that ships this feature:

- Set `MERCURE_JWT_SECRET` and `BROADCAST_CONNECTION=mercure` on the
  `production` environment via `cloud environment:variables` (or the
  dashboard) — a value distinct from the local dev one.
- No other Cloud-side resource to provision: confirmed via `cloud` CLI
  (v0.5.0) that there is no `mercure` resource type, only unrelated
  `websocket-application`/`websocket-cluster` (Reverb) ones.

## Open questions / risks

- `laravel/framework:13.x-dev` is a moving target until the next tagged
  release ships the Mercure driver; a `composer update` could pull in
  unrelated breaking changes from the `13.x` branch in the meantime. Pin via
  `composer.lock` as usual and re-pin to a stable tag once released.
- No auto-refresh of the Mercure subscriber cookie in the frontend client;
  syncs longer than `subscribe_expiration` (15 min) will silently stop
  receiving updates. Acceptable for now given current sync durations.
- Assumes Laravel Cloud runs this app's Octane worker through
  `php artisan octane:start --server=frankenphp` (or equivalent), which is
  what makes `config('octane.mercure')` take effect. This should be
  double-checked on the first deploy — if Cloud instead invokes FrankenPHP
  directly, `CADDY_SERVER_EXTRA_DIRECTIVES` would need to be set by hand as
  a Cloud environment variable instead.
