# Multi-provider foundation

## Problem

Sonder is built around YouTube Music. Around 46 backend files and 27 front-end
files name it; the schema has `youtube_music_accounts`, `youtube_music_syncs`,
`youtube_playlist_id` and `youtube_video_id`; `tracks` are per-playlist copies
with no catalogue; the player queue and `listens` are keyed by `videoId`.

That shape blocks what comes next:

- Sonder is meant to become the source of truth for the user's music, with
  YouTube Music as one adapter among others (Tidal is the likely second one:
  Dolby Atmos, better audio quality).
- Moving playlists from one service to another needs a track identity that is
  not a provider id.
- Enrichment (credits.fm for identifiers, credits and release data; Last.fm
  for listener counts and tags) needs stable track, album and artist entities
  to attach to.

The data is small today (one user, 17 playlists, a few listens) and the user
accepts losing playlists and tracks once, so this is the cheapest moment to
change the foundation. It will not stay true for long: the schema must be
designed to last.

## Guiding principle: a universal system

The user sees one catalogue, one library, one search and one queue. There is
no "YouTube" area and no "Tidal" area. Provenance is information (a badge),
never a partition of the UI or of the domain code. Domain actions and pages
manipulate Sonder entities only; providers exist behind the adapter contracts
and in link tables.

## Goals

- A provider-agnostic schema: provider accounts, a global catalogue (tracks,
  artists, albums) with links to providers, Sonder-owned playlists, favourites,
  listens pointing at the catalogue.
- A `Provider` enum listing providers, and adapter contracts split by
  capability, with YouTube Music re-implemented as the first adapter, isolated
  in its own namespace.
- Automatic, reversible merging of the same recording across sources (audio
  and music video on YouTube Music today, other providers later).
- Playback through a source chosen by user preferences (prefer the music video
  when it exists; provider order).
- Normalised, translated provider errors.
- Reconnecting an account does not block the user on the sync page when they
  already have playlists, and never duplicates playlists.

## Non-goals

- A Tidal (or any second) adapter.
- Writing to providers (adding a track or creating a playlist on YouTube
  Music), conflict resolution, deleting favourites that were removed upstream.
  All of this is sub-project C (operations journal and two-way sync).
- credits.fm and Last.fm enrichment (sub-project D).
- A manual merge / unmerge UI, and a clip/audio toggle in the player.
- The floating sync indicator and sync observability (sub-project A, next).
- Search features. Existing search keeps working, scoped to the user's
  library.
- Per-account or per-region source availability (see Known limitations).

## Decisions taken during design

| Question | Decision |
|---|---|
| Catalogue scope | Global, shared by all users. Playlists, favourites and listens are per user. Every read shown to a user is scoped to that user's library. |
| Connecting an account | Automatic import of every playlist, each linked to its source; a playlist can later be unlinked or ignored. |
| Disconnecting an account | Credentials are wiped and the account is marked disconnected; the account row, its links and the Sonder playlists are kept. Reconnecting the same external account reuses them. |
| Liked tracks | Separate favourites (user ↔ track), not a system playlist. YouTube Music's "Liked Music" maps to favourites. |
| Same recording, several sources | Hybrid merge: automatic on identical ISRC or strong normalised metadata match; method and score recorded; reversible. |
| Clip or audio | Merged into one track with two sources; a user preference picks which one plays (default: prefer the video). |
| Existing data | Re-import. Playlists, tracks and accounts are not migrated: the user reconnects their account once after deploying; listens are kept and relinked. |
| Adapter shape | Capability interfaces plus a registry keyed by the enum. |
| Errors | One translated exception hierarchy; persisted errors store a code, translated when displayed. |
| Delivery | One spec, three plans shipped in order (see Delivery). |

## Design

### Schema

All keys are UUIDs, as in the rest of the application. Database: PostgreSQL,
which does not index foreign keys automatically: every foreign key used for a
reverse lookup gets an explicit index below.

**Accounts and syncs**

| Table | Columns | Notes |
|---|---|---|
| `provider_accounts` | `id`, `user_id` (FK users, cascade), `provider` (string, enum `Provider`), `external_account_id` (not null), `display_name`, `credentials` (encrypted JSON, nullable), `last_verified_at` (nullable), `credentials_expired_at` (nullable), `disconnected_at` (nullable), timestamps | Several accounts per user and per provider. Unique `(user_id, provider, external_account_id)`. `credentials` is null exactly when `disconnected_at` is set. Replaces `youtube_music_accounts`. |
| `provider_syncs` | `id`, `provider_account_id` (FK, cascade), `status`, `total_playlists` (nullable), `synced_playlists`, `current_playlist_title` (nullable), `error_code` (nullable, enum `ProviderErrorCode`), `error_context` (JSON, nullable), `started_at`, `finished_at`, timestamps | Index `(provider_account_id, status)`. Replaces `youtube_music_syncs`. The error is stored as a code plus parameters and translated in the viewer's locale when displayed, never in the worker's. |

**Global catalogue**

| Table | Columns | Notes |
|---|---|---|
| `artists` | `id`, `name`, `normalized_name`, `mbid` (nullable, unique), timestamps | Index `normalized_name`. Not unique: two artists can share a name. |
| `albums` | `id`, `title`, `normalized_title`, `upc` (nullable, index), `release_date` (date, nullable), `release_date_precision` (nullable: `year`, `month`, `day`), timestamps | Index `normalized_title`. A known year only is stored as `YYYY-01-01` with precision `year`. |
| `tracks` | `id`, `title`, `match_title`, `match_artist`, `duration_seconds` (nullable), `isrc` (nullable, index), `is_explicit`, timestamps | The recording. `match_title` / `match_artist` are the normalised title (version markers kept) and normalised first main artist used by metadata matching; index `(match_title, match_artist)`. |
| `artist_track` | `artist_id`, `track_id`, `position`, `role` (`main`, `featured`) | Primary key `(track_id, artist_id, role)`; index `artist_id`. |
| `album_artist` | `album_id`, `artist_id`, `position` | Primary key `(album_id, artist_id)`; index `artist_id`. |
| `album_track` | `album_id`, `track_id`, `disc_number` (nullable), `track_number` (nullable), `created_at` | Primary key `(album_id, track_id)`; index `track_id`. A recording appears on several releases. Default album shown: earliest `release_date`, ties and unknown dates broken by `created_at`. |
| `track_sources` | `id`, `track_id` (FK, cascade), `provider`, `external_id`, `kind` (`audio`, `video`), `is_available`, `title`, `artists`, `duration_seconds` (nullable), `thumbnail_url` (nullable), `match_method` (`provider_ref`, `isrc`, `metadata`, `created`, `manual`), `match_score` (nullable decimal 0–1), timestamps | One playable version at a provider. Unique `(provider, external_id)`; index `track_id`. `title` / `artists` keep the source's own wording (needed to recompute or undo a merge). `thumbnail_url` is the raw provider URL; the Data layer turns it into the local thumbnail proxy URL. |
| `artist_links` | `id`, `artist_id` (FK, cascade), `provider`, `external_id`, timestamps | Unique `(provider, external_id)`; index `artist_id`. |
| `album_links` | `id`, `album_id` (FK, cascade), `provider`, `external_id`, timestamps | Unique `(provider, external_id)`; index `album_id`. |

Registry identifiers (ISRC, UPC, MBID) are typed columns on the entities, not
links: they are universal identifiers that drive merging, not streaming
providers. Explicit link tables are preferred over one polymorphic
`provider_links` table to keep real foreign keys and per-use typed columns.

**User library**

| Table | Columns | Notes |
|---|---|---|
| `playlists` | `id`, `user_id` (FK, cascade), `title`, `description` (nullable), `cover_url` (nullable), `track_count`, `duration_seconds`, timestamps | The user's playlist, owned by Sonder. Cover, count and duration are Sonder's own values (count and duration recomputed when items change; cover taken from the remote when imported, raw provider URL). Index `(user_id, title)`. |
| `playlist_items` | `id`, `playlist_id` (FK, cascade), `track_id` (FK, cascade), `track_source_id` (nullable FK, null on delete), `position`, `added_at`, timestamps | Duplicates allowed. `track_source_id` records which source the occurrence came from, so an unmerge can move it. Index `(playlist_id, position)`; index `track_id`. |
| `playlist_links` | `id`, `playlist_id` (nullable FK, **null on delete**), `provider_account_id` (FK, cascade), `external_id`, `status` (`linked`, `ignored`, `broken`), `title`, `track_count` (nullable), `thumbnail_url` (nullable), `author` (nullable), `fingerprint` (nullable), `last_checked_at` (nullable), `last_changed_at` (nullable), timestamps | Link to a remote playlist and its sync state. Unique `(provider_account_id, external_id)`; partial unique index `(playlist_id) WHERE status = 'linked'` (one pulling link per playlist until sub-project C handles conflicts). Deleting a Sonder playlist sets its links to `ignored` (and `playlist_id` to null), so the next sync does not re-import it. |
| `favorite_tracks` | `user_id` (FK, cascade), `track_id` (FK, cascade), `favorited_at` | Primary key `(user_id, track_id)`; index `track_id`. Which provider a favourite came from is not recorded yet (sub-project C adds it). |
| `listens` | existing columns, minus `youtube_video_id` and `youtube_playlist_id`; plus `track_id` (nullable FK, null on delete), `track_source_id` (nullable FK, null on delete), `playlist_id` (nullable FK, null on delete), `source_provider`, `source_external_id`, `source_playlist_external_id` (nullable) | The `source_*` columns permanently snapshot what was played and from which remote playlist, next to the existing `title` / `artists` snapshot. Index `(user_id, started_at)` kept; indexes `track_id`, `track_source_id`, `(source_provider, source_external_id)`. |
| `users` (added) | `prefers_video_sources` (boolean, default true), `provider_order` (JSON, nullable) | Playback source preferences. |

The sync state lives on `playlist_links`, never on `playlists`: a playlist does
not know where it comes from, its links do.

**Merge and unmerge.** Unmerging moves one `track_sources` row to a new
`tracks` row and, in the same transaction, moves the `playlist_items` whose
`track_source_id` is that source and rewrites `listens.track_id` for the
listens whose `track_source_id` is that source. Merging rewrites `track_id` on
items, favourites and listens of the absorbed track. Favourites follow the
surviving track on merge and stay on it on unmerge. (The UI for this is out of
scope; the schema and an action supporting it are in scope for plan 3.)

Additive later, deliberately not created now: `credits` (songwriters,
producers), `works` (ISWC, grouping live/cover/remix versions, with a nullable
`tracks.work_id`), `enrichments` (entity, source, status, fetched_at, raw
payload), listener statistics over time, per-item remote ids for writing
(sub-project C).

### Object architecture

```
app/Enums/Provider.php              YouTubeMusic = 'youtube_music' (Tidal later); label()
app/Enums/SourceKind.php            Audio, Video
app/Enums/MatchMethod.php           ProviderRef, Isrc, Metadata, Created, Manual
app/Enums/PlaylistLinkStatus.php    Linked, Ignored, Broken
app/Enums/ProviderErrorCode.php     CredentialsRejected, Unavailable, RateLimited
app/Enums/ProviderSyncStatus.php    replaces YouTubeMusicSyncStatus (same cases)

app/Services/Music/
├── Contracts/
│   ├── ProviderAdapter.php         provider(): Provider
│   │                               account(ProviderCredentials): RemoteAccount   (never cached)
│   ├── ReadsPlaylists.php          playlists(ProviderCredentials): list<RemotePlaylistSummary>
│   │                               playlist(ProviderCredentials, string $externalId, ?int $trackCountHint): RemotePlaylist
│   ├── ReadsFavorites.php          favorites(ProviderCredentials): list<RemoteTrack>
│   └── ProviderCredentials.php     provider(): Provider; toArray(): array; static fromArray(array): static
├── Data/
│   ├── ProviderRef.php             (Provider $provider, string $externalId)
│   ├── RemoteAccount.php           (ProviderRef $ref, string $displayName)   ref required
│   ├── RemotePlaylistSummary.php   (ref, title, description, trackCount, thumbnailUrl, author); fingerprint()
│   ├── RemotePlaylist.php          (RemotePlaylistSummary $summary, list<RemoteTrack> $tracks)
│   ├── RemoteTrack.php             (?ProviderRef $ref, title, list<RemoteArtist>, ?RemoteAlbum,
│   │                                ?durationSeconds, ?isrc, SourceKind $kind, isExplicit,
│   │                                isAvailable, ?thumbnailUrl)
│   ├── RemoteArtist.php            (?ProviderRef $ref, string $name, ArtistRole $role)
│   └── RemoteAlbum.php             (?ProviderRef $ref, string $title, ?int $year)
├── Exceptions/
│   ├── ProviderException.php       abstract, extends RuntimeException
│   │                               provider(); code(): ProviderErrorCode; context(): array; userMessage(): string
│   ├── CredentialsRejected.php
│   ├── ProviderUnavailable.php
│   └── ProviderRateLimited.php     + int $retryAfter
├── ProviderRegistry.php            adapter(Provider): ProviderAdapter
│                                   capability(Provider, class-string<T>): ?T      (@template T)
│                                   credentialsClass(Provider): class-string<ProviderCredentials>
├── Catalog/
│   ├── TrackResolver.php           resolve(RemoteTrack): ResolvedTrack(Track, ?TrackSource)
│   ├── Matchers/TrackMatcher.php   interface: match(RemoteTrack): ?TrackMatch
│   ├── Matchers/TrackMatch.php     (Track $track, MatchMethod $method, float $score)
│   ├── Matchers/ProviderRefMatcher.php
│   ├── Matchers/IsrcMatcher.php
│   ├── Matchers/MetadataMatcher.php        (plan 3)
│   ├── TrackNormalizer.php
│   ├── CatalogWriter.php           creates tracks, artists, albums and their pivots/links
│   └── PlaybackSourceSelector.php  select(Track, User): ?TrackSource
└── YouTubeMusic/
    ├── YouTubeMusicAdapter.php     implements ProviderAdapter, ReadsPlaylists, ReadsFavorites
    ├── YouTubeMusicCredentials.php (string $cookie)
    ├── Gateway/YouTubeMusicGateway.php   interface over raw calls
    ├── Gateway/YtmusicapiGateway.php     ytmusicapi + CookielessSession
    ├── Gateway/RateLimitedGateway.php    decorator (per-account call budget, as today)
    ├── CookielessSession.php
    ├── YouTubeMusicMapper.php      payloads → Remote* DTOs
    └── YouTubeMusicErrorTranslator.php   native failures → ProviderException subclasses
```

`ArtistRole` (`Main`, `Featured`) lives in `app/Enums`.

Responsibilities and rules:

- **Capabilities are separate interfaces** (interface segregation). Domain code
  asks the registry for a capability and gets `null` when the provider lacks
  it; the return type is generic, so PHPStan knows it.
- **The enum carries identity only** (label, later badge colour or icon). A
  service provider registers, per enum case, the adapter class and the
  credentials class in the registry; the enum never references concrete
  classes. The registry resolves adapters through the container on each call
  (no request state held: Octane-safe).
- **Adapters know nothing about Eloquent.** They take credentials and return
  DTOs; domain actions and `CatalogWriter` write to the database.
- **No account cache.** `account()` is always a live call (verification
  relies on it); the display name is stored on `provider_accounts`, so nothing
  needs the cached lookup `CachedClient` provides today. Rate limiting stays
  in the YouTube Music gateway decorator: it is that provider's constraint.
- **Credentials are typed per provider.** `provider_accounts.credentials` is
  cast through a custom cast that encrypts the JSON and rebuilds the class the
  registry associates with the account's `provider`. An adapter receiving
  another provider's credentials throws `LogicException`.
- **Signed-out detection belongs to the adapter.** Only the adapter knows what
  a signed-in response looks like. The YouTube Music adapter checks the raw,
  unfiltered library (a signed-in library always lists "Liked Music"; an empty
  one means signed out) and throws `CredentialsRejected` before filtering. The
  domain never infers credential state from an empty list: an empty
  `playlists()` result is a valid empty library.
- **Adapter filtering.** The YouTube Music adapter drops "Liked Music" (served
  through `ReadsFavorites`) and non-music system playlists ("Episodes for
  Later" and the like) from `playlists()`, and drops podcast episodes from
  playlist contents. User-uploaded videos (UGC) are kept as `video` sources.
- **Errors are normalised and translated.**
  - `ProviderException` extends `RuntimeException`. `getMessage()` is a
    technical English message (logs, Grafana); `code()` and `context()`
    describe the failure; `userMessage()` translates it with `__()` and the
    provider label, in the current locale.
  - `YouTubeMusicErrorTranslator` classifies ytmusicapi failures:
    a signed-out response (`logged_in=0`, missing account header, empty raw
    library) or HTTP 401/403 → `CredentialsRejected`; Sonder's own call budget
    → `ProviderRateLimited`; transport errors, HTTP 5xx, timeouts and
    unreadable payloads → `ProviderUnavailable`. Only `CredentialsRejected`
    marks credentials expired.
  - Controllers display `userMessage()`. Syncs persist `code()` / `context()`
    in `provider_syncs.error_code` / `error_context`; the Data layer
    translates them when rendering.
  - Every handled `ProviderException` is passed to `report()`, except
    `ProviderRateLimited` (expected, frequent, not an incident).
- **Matching strategies are interchangeable.** `TrackResolver` receives an
  ordered list of `TrackMatcher`s from the container. A future
  `CreditsFmIsrcMatcher` slots in without touching the resolver.

### Domain actions

All in `app/Actions`, provider-agnostic, depending on `Contracts` only:

- `ConnectProviderAccount::handle(User, Provider, ProviderCredentials): ProviderAccount`
- `DisconnectProviderAccount::handle(ProviderAccount): void` (wipes
  credentials, sets `disconnected_at`, keeps links and playlists)
- `StartProviderSync::handle(ProviderAccount): ProviderSync` (lock per
  account, reuses an active sync, as today)
- `ImportProviderLibrary::handle(ProviderAccount, ?Closure $onProgress): void`
- `ImportRemotePlaylist::handle(PlaylistLink, RemotePlaylist): void`
- `RefreshPlaylist::handle(Playlist): bool` (manual refresh: fetches the
  playlist through its `linked` link, keeps the fingerprint untouched as
  today; no link → nothing to refresh)
- `SyncPlaylistItems::handle(Playlist, list<ResolvedTrack>): bool` (the
  current per-occurrence diff applied to `track_id`; recomputes
  `track_count` / `duration_seconds`)
- `ImportFavorites::handle(ProviderAccount, list<RemoteTrack>): void`
- `RelinkListens::handle(User): void`
- `VerifyProviderCredentials::handle(ProviderAccount): bool` (live
  `account()` call; replaces `VerifyYouTubeMusicCookie`; the daily command
  iterates every connected account)
- `CheckLibraryFreshness::handle(User)`, `SummarizeLibrary::handle(User)`,
  `SampleLibraryTracks::handle(User, …)`: re-keyed on the user (across all
  their connected accounts) instead of a YouTube Music account.
- `DeletePlaylist::handle(Playlist): void` (sets its links to `ignored`, then
  deletes; no UI in this sub-project, used by tests and future UI)

Job `SyncProviderAccount` replaces `SyncYouTubeMusicLibrary` with the same
retry, `WithoutOverlapping`, release-on-rate-limit and broadcast behaviour.
Event `ProviderSyncUpdated` replaces `YouTubeMusicSyncUpdated` on channel
`provider-sync.{id}` (authorised in `routes/channels.php` for the account's
owner).

### Carried over from the current application

Each of these keeps its behaviour, rewired onto the new model:

- Single-playlist refresh (`PlaylistRefreshController`, its throttle, the
  "playlist removed upstream" guard, now "link broken").
- Shared props: `library` (`LibraryData`) gains a list of connected accounts
  (provider, display name, credentials expired) instead of one
  `accountName`; the `youtubeMusicCookieExpired` prop and
  `YouTubeMusicCookieAlert.vue` become a per-account "reconnect" alert.
- `CheckLibraryFreshness` on the playlists and discover pages; "no connected
  account → connections page"; "no playlist → sync page".
- `SummarizeLibrary` stats and `SampleLibraryTracks` suggestions pool.
- The sidebar sync status (`SyncStatus.vue` / `SyncListener.vue`) on the new
  channel.
- The daily scheduled verification (renamed command
  `providers:verify-credentials`).
- Translations (`lang/fr_BE.json`: YouTube-specific strings reworded or
  parameterised with the provider label), regenerated TypeScript types, and
  Wayfinder actions.

### Import and resolution flow

**Connecting** (`ConnectProviderAccount`)

1. The adapter verifies the credentials (`account()`), returning the external
   account id and display name. No external id → connection fails with
   `CredentialsRejected`.
2. Upsert `provider_accounts` on `(user, provider, external_account_id)`:
   update credentials and display name, clear `credentials_expired_at` and
   `disconnected_at`, set `last_verified_at`. Reconnecting the same external
   account therefore reuses the row and all its links: no duplicate
   playlists.
3. Start a sync. The controller redirects to the sync page only when the user
   has no playlist yet; otherwise to the playlists page, where the sidebar
   status shows progress.

**Syncing** (`ImportProviderLibrary`)

1. Fetch playlist summaries (`ReadsPlaylists`). Credential problems surface as
   `CredentialsRejected` from the adapter.
2. For each summary, find the `playlist_links` row by account and external id:
   - `ignored`: skip.
   - missing: create the Sonder playlist (title, description, cover) and a
     `linked` link (automatic import).
   - same fingerprint and not `broken`: update `last_checked_at` only.
   - otherwise: fetch the playlist (with the summary's track count as hint),
     resolve every track, apply `SyncPlaylistItems`, update the Sonder
     playlist's title, description and cover from the remote, update the
     link's details, fingerprint, `last_checked_at` and, when anything
     changed, `last_changed_at`. A `broken` link that reappears goes back to
     `linked`.
3. Links of this account absent from the summaries become `broken`.
4. Favourites (`ReadsFavorites`, when the adapter has it): resolve and add the
   missing ones with `insertOrIgnore`. Nothing is ever removed in this
   sub-project.
5. `RelinkListens`: set `track_source_id` and `track_id` on the user's listens
   whose `(source_provider, source_external_id)` matches a `track_sources` row
   and whose `track_source_id` is null.
6. Progress is broadcast as today.

The sync only pulls in this sub-project: remote changes apply to the linked
Sonder playlist.

**Resolving a track** (`TrackResolver`)

Resolution of a playlist runs outside any long transaction: each track is
resolved and written on its own, so one failure never aborts the others, and
`SyncPlaylistItems` then applies the item diff in one short transaction.

1. A remote track with a ref runs the matchers in order:
   `ProviderRefMatcher` (a source with this `(provider, external_id)` exists:
   refresh its availability, duration, title, artists and thumbnail; return
   it), then `IsrcMatcher`, then `MetadataMatcher` (plan 3).
2. When no matcher answers, `CatalogWriter` creates the track, finds or
   creates its artists (by `artist_links`, otherwise by `normalized_name`
   among artists without a link for this provider) and album (by
   `album_links`, otherwise by `normalized_title` + first main artist), fills
   the pivots, and attaches the source with `match_method = created`.
3. A remote track **without a ref** (removed or unavailable upstream, no
   playable id) is resolved by metadata only (plan 3) or created as a track
   with no source; it stays in the playlist as an unplayable item, as today.
4. **Canonical metadata.** A track's `title`, `match_title` and artists come
   from its first `audio` source when it has one, otherwise from its first
   source with the video markers stripped. When an audio source merges into a
   track created from a video, the track's title and artists are rewritten
   from the audio source.
5. **Artist roles.** The adapter assigns roles: artists listed in a "feat." /
   "ft." clause of the title are `featured`; all listed artists are `main`
   otherwise. Matching compares the first main artist only, because YouTube
   Music audio lists every artist while its video lists one.

**Concurrency (PostgreSQL)**

- A unique violation aborts the whole PostgreSQL transaction, so retrying
  inside it cannot work. Every insert that can race uses
  `insertOrIgnore` (`ON CONFLICT DO NOTHING`) on its unique key, then
  re-selects: `track_sources (provider, external_id)`,
  `artist_links` / `album_links (provider, external_id)`,
  `favorite_tracks (user_id, track_id)`, `album_track`, `artist_track`.
- Artists and albums found by name have no unique key (homonyms exist); a
  rare duplicate between two concurrent syncs is accepted and merges later
  through links or the re-match command.
- The sources already known for a remote playlist are preloaded in one query
  (`whereIn` on external ids).
- The per-account lock and `WithoutOverlapping` stay.

**Metadata matching rules** (`TrackNormalizer`, used by `MetadataMatcher` in
plan 3; `TrackNormalizer` and `match_*` columns exist from plan 2)

- Unicode-aware normalisation: NFKD, remove combining marks (`\p{Mn}`),
  lowercase, keep letters and digits of every script (`\p{L}\p{N}`), collapse
  whitespace. Non-Latin titles keep their characters.
- Remove bracketed or dash-separated video markers, matched as whole words or
  phrases: "official video", "official music video", "official audio",
  "official visualizer", "lyric video", "lyrics", "visualizer",
  "clip officiel", "hd", "4k", "mv". Remove "feat." / "ft." clauses.
- Artists: same normalisation; strip a trailing "topic" channel suffix
  (" - Topic").
- Version markers are part of identity and are never stripped, matched as
  whole words: "live", "remix", "acoustic", "instrumental", "remaster",
  "remastered", "edit", "version", "demo", "slowed", "sped up", "karaoke",
  "cover". Two tracks whose marker sets differ never match ("Demons" is not
  "demo"; "Credit" is not "edit").
- Never match when the normalised title or artist is empty.
- Both durations must be known and within ±3 s. Score 1.0 for identical
  `match_title` and `match_artist` within ±1 s, 0.9 within ±3 s. Only
  scores ≥ 0.9 merge.
- ISRC shared by several tracks: `IsrcMatcher` picks the oldest track.
- Known limit: YouTube Music videos are often longer (intro, outro) and will
  not always merge with the audio. They stay separate, the safe failure.

**Re-match** (plan 3): a `catalog:rematch` command re-runs the non-ref
matchers over existing tracks and merges duplicates, so a matcher added later
(credits.fm) fixes past imports, not only new ones.

### Player and listens

**Server**

- `TrackData` (sent to the front end) is built from the catalogue: `id`,
  `title`, `artists` (display string), `album` (nullable), `duration`,
  `durationSeconds`, `isExplicit`, `thumbnailUrl` (local proxy URL of the
  chosen source's thumbnail) and
  `source: PlayableSourceData { id, provider, externalId, kind } | null`.
  `isAvailable` is derived from `source !== null`.
- `PlaybackSourceSelector` picks among available sources: kind first (`video`
  when `prefers_video_sources`, else `audio`), then the user's
  `provider_order`, then enum order, then the oldest source.

**Front end**

- `QueueTrack` carries `trackId` and `source` instead of `videoId`. The
  persisted queue key becomes `sonder.player.v2`; the v1 entry is ignored.
- `QueueSource.playlistId` and `isPlayingFrom()` use Sonder playlist ids.
- `PlayerHost` owns a pool of transports, one per provider, created on demand
  from a registry `Provider → (element) => PlayerTransport` (YouTube only
  today). The store drives the transport of the current track's provider and
  pauses the others.
- The debug panel compares the expected source (provider, external id) with
  what the transport actually plays.

**Listens**

- Payload: `id`, `track_source_id`, `title`, `artists`, `playlist_id`
  (nullable), `origin`, `end_reason`, `started_at`, `ended_at`,
  `position_seconds`, `listened_seconds`, `duration_seconds`.
- The server derives `track_id`, `source_provider` and `source_external_id`
  from the source (so a queue persisted before a merge still records
  correctly), and `source_playlist_external_id` from the playlist's linked
  remote playlist when there is one.
- Validation: the source exists; the playlist, when present, belongs to the
  authenticated user.
- The `sonder.listens` counter gains a `provider` attribute.

**Rest of the application**

Playlists, discover, suggestions, stats and search read the catalogue
through the user's `playlist_items` and `favorite_tracks`: a user never sees
tracks outside their library. The provenance badge comes from
`playlist_links` and `track_sources`. The only provider-specific page is
account connection, which becomes "Connected accounts" with one card per
provider (only YouTube Music can be connected for now). Routes
`youtube-music/*` become `connections/*` with the provider as a parameter; no
compatibility redirects.

### Migration and deploy (plan 2)

1. Enable maintenance mode and let the queue drain (pending
   `SyncYouTubeMusicLibrary` jobs would fail on the removed class).
2. Create the new tables.
3. Convert `listens`: add the new columns; copy `youtube_video_id` into
   `source_external_id` with `source_provider = youtube_music` and
   `youtube_playlist_id` into `source_playlist_external_id`; drop the two old
   columns. Done with the query builder, not with models being removed.
4. Drop `tracks`, `playlists`, `youtube_music_syncs` and
   `youtube_music_accounts`. Accounts are not migrated: the user reconnects
   once (pasting the cookie), which yields a verified `external_account_id`.
5. Disable maintenance mode. Reconnecting starts the full sync;
   `RelinkListens` reattaches listens at its end.
6. `down()` recreates the previous schema without data (accepted). A database
   snapshot is taken on Laravel Cloud before deploying.

### Error handling

- `CredentialsRejected` during a sync: mark the account's credentials expired,
  fail the sync with its code, report.
- `ProviderRateLimited`: the job releases itself after `retryAfter`; a request
  shows the user message; not reported.
- `ProviderUnavailable`: fail the sync with its code, report; credentials are
  not marked expired.
- One track failing to resolve (unexpected payload): skipped, reported, the
  playlist import continues.

### Known limitations

- `track_sources.is_available` is global: availability that depends on
  region or account is not modelled. Acceptable with a single user; to
  revisit before opening Sonder to others.
- Videos longer than their audio are not merged by metadata (see matching).
- Concurrent syncs may create a duplicate artist or album found by name.

### Delivery

One spec, three plans, shipped in order, each leaving the application
working:

1. **Provider abstraction behind the current schema.** `Provider` enum,
   contracts, DTOs, exception hierarchy and translator, registry, the YouTube
   Music adapter (gateway, mapper, signed-out detection, filtering), and the
   current actions/job/controllers rewired onto contracts and
   `ProviderException`, still writing today's tables. Pure refactor; ships
   alone.
2. **Catalogue, library and player.** New schema, migration and deploy steps,
   `TrackResolver` with `ProviderRefMatcher` and `IsrcMatcher`,
   `CatalogWriter`, import, favourites, relinking, disconnect/reconnect,
   connected-accounts page, everything in "Carried over", player sources and
   transport pool, listens. One deploy.
3. **Metadata merging.** `MetadataMatcher`, merge/unmerge actions, the
   `catalog:rematch` command.

### Testing

**Unit**

- `TrackNormalizer` with a dataset of real titles: "(Official Video)",
  "feat.", "- Topic", "Clip officiel", Japanese, Cyrillic and Arabic titles,
  marker-only titles, "Demons" vs "demo".
- Each matcher in isolation; `TrackResolver`: audio and video merged (plan 3);
  ISRC merge; no false merge for live, remix, acoustic, remaster versions,
  non-Latin homonyms, missing durations or durations off by more than 3 s;
  canonical title rewritten when audio joins a video-created track.
- `PlaybackSourceSelector`: video preferred, audio preferred, provider order,
  tie-break, unavailable sources skipped, no source.
- `ProviderRegistry`: capability present and absent, credentials class.
- Exceptions: translated user messages; `YouTubeMusicErrorTranslator`
  classification (signed out, 401/403, 5xx, timeout, unreadable payload).
- `YouTubeMusicMapper`: new fixtures carrying `videoType` (audio, video, UGC,
  podcast episode), tracks without `videoId`, system playlist filtering,
  signed-out detection on the raw library.

**Feature** (with a configurable `FakeProviderAdapter` in `tests/Support`
implementing the capabilities)

- `ImportProviderLibrary`: automatic import, fingerprint skip, ignored link,
  broken link and its return, title change applied, deleted playlist not
  re-imported, favourites added and never removed, item diff with duplicates,
  unplayable items kept, listens relinked, empty library accepted without
  expiring credentials.
- Connect / disconnect / reconnect: same external account reuses the row and
  links, no duplicate playlist; redirect rules.
- Concurrency: resolving the same remote track twice yields one source.
- Listen submission and its validation (server-derived track and snapshot).
- Controllers, channel authorisation, the daily verification command, the
  listens migration.

**Architecture (Pest arch)**

- Nothing outside `App\Services\Music\YouTubeMusic` uses that namespace or
  `Ytmusicapi`.
- `App\Actions` depends on the `App\Services\Music\Contracts` interfaces, never
  on a concrete adapter.

**Front end** (Vitest)

- `usePlayer` over `source`; the transport pool pauses the other provider's
  transport when the provider changes; browser tests updated.

**Manual**

- A real local sync (17 playlists), playback, listens recorded and relinked;
  then production after deploying.

### Dependencies

None added. `ytmusicapi/ytmusicapi`, `spatie/laravel-data` and the existing
front-end stack are enough.
