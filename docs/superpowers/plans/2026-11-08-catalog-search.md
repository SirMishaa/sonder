# Catalog Search, Artist & Album Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Search the whole YouTube Music catalog from ⌘K and a /search page, browse artist, album and public-playlist pages, and play or queue any track, with library, listening and genre data overlaid everywhere.

**Architecture:** A new `BrowsesCatalog` provider capability, implemented by `YouTubeMusicAdapter` on top of four new anonymous (cookieless) gateway calls, mapped to catalog DTOs. Cached actions describe search results, artists, albums and public playlists; per-user overlay actions add the library flags, listen stats and genres. Inertia pages and a widened palette render it through one shared track row.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia v3 + Vue 3 + Tailwind 4, ytmusicapi 1.12.2 (PHP port), spatie/laravel-data + typescript-transformer, Wayfinder, Pest 4, Vitest (`bun run test`).

**Spec:** `docs/superpowers/specs/2026-11-08-catalog-search-design.md`. Visual reference: `.impeccable/prototype/search.html` (variant B everywhere) with `.impeccable/prototype/rammstein.js`; serve the folder (`python3 -m http.server 8401 --bind 127.0.0.1 -d .impeccable/prototype`) and match it.

## Global Constraints

- Read `.ai/rules/general.md` and `.ai/rules/js.md` first; follow sibling-file conventions.
- Commits: Conventional Commits `type(scope): subject`, lowercase, imperative, no trailing period, **no Co-Authored-By or any AI attribution**; never fold unrelated working-tree drift (the pre-existing `.gitignore`, `.mcp.json`, `bun.lock`, `composer.*`, `package.json`, `.ai/mcp/` changes) into these commits.
- Every modified PHP file: `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyze --level 8 <files>`.
- PHP tests: `./vendor/bin/pest --tia --parallel <path>`. JS tests: `bun run test <path>`. Types: `bun run test:types`. Never `npm`/`npx`.
- Actions in `app/Actions`, `final readonly`, single `handle()`, created with `php artisan make:action "<Name>" --no-interaction`. Concise class/method docblocks, strongest generics in PHPDoc; no superfluous annotations.
- Vue: import Wayfinder **controller objects** (`@/actions/App/Http/Controllers/...`), never `@/routes/*`. Every string through `$t`/`trans`, with fr (`lang/fr_BE.json`) and en entries.
- Catalog calls never use the user's cookie. Catalog cache keys hold no user data.
- Design: DESIGN.md. Amber only for action and "now playing"; no `#000`/`#fff`; skeletons, never centered spinners; motion 140–520 ms `cubic-bezier(0.22, 1, 0.36, 1)`, reduced motion honored; works at phone width with no horizontal page scroll. Use the `impeccable` skill on UI tasks.
- Cache TTLs: search 10 min; artist, album, public playlist, album track count 24 h.
- Rate limiting goes through Laravel's `RateLimiter` everywhere (no hand-rolled counters):
  - outbound: limiter `youtube-music-catalog` defined with `RateLimiter::for()` in `AppServiceProvider`, 120 calls/min and 3000/hour on one global key, spent by `RateLimitedGateway` through `Illuminate\Cache\RateLimiter` like the existing `youtube-music` limiter;
  - inbound: limiter `catalog` defined with `RateLimiter::for()`, keyed by user id, `Limit::perMinute(60)` for pages and `Limit::perMinute(120)` for `/search/suggestions` (two named limiters `catalog` and `catalog-suggestions`), applied with the `throttle:` middleware on the catalog routes; over the limit, suggestions return 429 (the palette treats it as `failed`) and pages render `catalogError`.

## Review Focus

1. **A query with no results or only podcasts/episodes**: the page shows an empty state naming the query, not a blank page or a crash. → test in Task 4 (`it returns empty sections for a query with only episodes`) and Task 7 (`it renders an empty search`).
2. **YouTube Music down or rate limited mid-browse**: pages render an inline error with retry, the palette keeps its library group. → tests in Task 7 (`it shows a catalog error instead of failing`) and Task 11 (Vitest `keeps library results when suggestions fail`).
3. **Payload gaps** (song with empty `artists`, artist top song without duration, search song without album, album without year): mapped without exceptions, rendered with "—"/hidden fields, sorted last by date. → tests in Task 3 (fixture cases) and Task 9 (`unknown years sort last`).
4. **Opening a public playlist that is already in the library**: redirect to the library page, not a duplicate read-only view. → test in Task 7.
5. **Very fast typing in ⌘K**: stale suggestion responses never overwrite newer ones. → test in Task 11 (`ignores a response for an older query`).

---

### Task 1: Catalog DTOs, capability contract and test fake

**Files:**
- Create: `app/Services/Music/Contracts/BrowsesCatalog.php`
- Create: `app/Services/Music/Data/CatalogFilter.php` (enum), `CatalogResults.php`, `CatalogArtist.php`, `CatalogAlbum.php`, `CatalogArtistSummary.php`, `CatalogAlbumSummary.php`, `CatalogPlaylistSummary.php`, `CatalogVideo.php`
- Modify: `tests/Support/FakeProviderAdapter.php`
- Test: `tests/Unit/Services/Music/Data/CatalogResultsTest.php`

**Interfaces:**
- Produces:
  - `enum CatalogFilter: string { case Tracks = 'tracks'; case Albums = 'albums'; case Artists = 'artists'; case Playlists = 'playlists'; }`
  - `interface BrowsesCatalog { search(string $query, ?CatalogFilter $filter = null, int $limit = 20): CatalogResults; artist(string $artistId): CatalogArtist; album(string $albumId): CatalogAlbum; publicPlaylist(string $playlistId): RemotePlaylist; }` — all `@throws ProviderException`.
  - `CatalogArtistSummary(ProviderRef $ref, string $name, ?string $thumbnailUrl, ?string $subscribers)`
  - `CatalogAlbumSummary(ProviderRef $ref, string $title, string $type, ?int $year, list<RemoteArtist> $artists, ?string $thumbnailUrl, bool $isExplicit)` (`type`: provider label, e.g. "Album", "Single", "EP")
  - `CatalogPlaylistSummary(ProviderRef $ref, string $title, ?string $author, ?int $trackCount, ?string $thumbnailUrl)`
  - `CatalogVideo(ProviderRef $ref, string $title, ?string $thumbnailUrl, ?int $views)`
  - `CatalogResults(?CatalogArtistSummary $topArtist, list<RemoteTrack> $tracks, list<CatalogAlbumSummary> $albums, list<CatalogArtistSummary> $artists, list<CatalogPlaylistSummary> $playlists)` with `isEmpty(): bool`.
  - `CatalogArtist(CatalogArtistSummary $summary, ?string $monthlyListeners, ?int $views, ?string $description, list<RemoteTrack> $topTracks, list<CatalogAlbumSummary> $albums, list<CatalogAlbumSummary> $singles, list<CatalogVideo> $videos, list<CatalogArtistSummary> $related)`
  - `CatalogAlbum(CatalogAlbumSummary $summary, ?string $description, int $trackCount, ?int $durationSeconds, list<RemoteTrack> $tracks, array<string, int> $playsByVideoId)`
  - `FakeProviderAdapter` implements `BrowsesCatalog`: public `?CatalogResults $searchResults`, `array<string, CatalogArtist> $artists`, `array<string, CatalogAlbum> $albums`, `array<string, RemotePlaylist> $publicPlaylists`; honours `$unavailable`/`$rateLimitedFor`; records calls in `$calls` as `['method' => 'search', 'query' => …]`; static builders `aCatalogArtist(string $id = 'UCs6GGpd9zvsYghuYe0VDFUQ', string $name = 'Rammstein')`, `aCatalogAlbum(string $id = 'MPREb_LJSVi8szMQL', string $title = 'Zeit', int $year = 2022, int $trackCount = 11)`, `aCatalogResults()`.

- [ ] **Step 1: Write the failing test** — `it reports empty results` (`new CatalogResults(null, [], [], [], [])->isEmpty()` is true) and `it is not empty with a top artist only`.
- [ ] **Step 2: Run** `./vendor/bin/pest --tia --parallel tests/Unit/Services/Music/Data/CatalogResultsTest.php` — Expected: FAIL, class not found.
- [ ] **Step 3: Create the contract, enum and readonly DTOs** with the signatures above (constructor promotion, one-line class docblocks), then extend `FakeProviderAdapter`.
- [ ] **Step 4: Run the test** — Expected: PASS. Run PHPStan level 8 on the new files.
- [ ] **Step 5: Commit** `feat(catalog): add catalog dtos and browses-catalog capability`

### Task 2: Anonymous catalog calls in the gateway

**Files:**
- Modify: `app/Services/Music/YouTubeMusic/Gateway/YouTubeMusicGateway.php`, `YtmusicapiGateway.php`, `RateLimitedGateway.php`
- Modify: `app/Providers/AppServiceProvider.php` (new limiter)
- Modify: `tests/Support/FakeYouTubeMusicGateway.php`
- Test: `tests/Unit/Services/Music/YouTubeMusic/RateLimitedGatewayTest.php`

**Interfaces:**
- Produces on `YouTubeMusicGateway` (no cookie parameter): `search(string $query, ?string $filter, int $limit): array` (list<object>), `artist(string $channelId): object`, `album(string $browseId): object`, `publicPlaylist(string $playlistId): object`.
- `RateLimitedGateway::CATALOG_LIMITER = 'youtube-music-catalog'`, spent with key `catalog` for these four methods.
- `FakeYouTubeMusicGateway`: public `array<string, list<object>> $searches` (keyed by query), `array<string, object> $artists`, `$albums`, `$publicPlaylists`; `record()` accepts `['method' => …, 'id' => …]` with an empty cookie.

- [ ] **Step 1: Write the failing tests** — `it refuses catalog calls over the catalog budget` (exhaust 120 hits on key `youtube-music-catalog:minute:catalog`, expect `ProviderRateLimited` from `search`) and `it keeps the catalog budget apart from account budgets` (exhaust the account limiter for a cookie, `artist()` still goes through).
- [ ] **Step 2: Run** the test file — Expected: FAIL.
- [ ] **Step 3: Implement.** `YtmusicapiGateway`: reuse `call()` with a cookieless `YTMusic` (`new YTMusic(requests_session: $observer->attach(CookielessSession::create()))`), endpoint names `search`, `artist`, `album`, `public_playlist`; `search` maps `$filter` to ytmusicapi filters `songs|albums|artists|playlists`, returns `array_values(array_filter($payload, is_object(...)))`. Limiter in `AppServiceProvider`: `Limit::perMinute(120)->by('youtube-music-catalog:minute:'.$key)`, `Limit::perHour(3000)->by('youtube-music-catalog:hour:'.$key)`. Make `spendCall` take the limiter name.
- [ ] **Step 4: Run** the test file — Expected: PASS. PHPStan on the four files.
- [ ] **Step 5: Commit** `feat(catalog): call youtube music anonymously for the catalog`

### Task 3: Map catalog payloads and implement the capability

**Files:**
- Create: `tests/Fixtures/YouTubeMusic/catalog-search.json`, `catalog-artist.json`, `catalog-album.json`
- Modify: `app/Services/Music/YouTubeMusic/YouTubeMusicMapper.php`, `YouTubeMusicAdapter.php`
- Test: `tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicCatalogMapperTest.php`, `tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicAdapterTest.php`

**Interfaces:**
- Consumes: Task 1 DTOs, Task 2 gateway methods.
- Produces: `YouTubeMusicMapper::searchResults(list<object> $payload): CatalogResults`, `artist(object $payload, string $channelId): CatalogArtist`, `album(object $payload, string $browseId): CatalogAlbum`; `YouTubeMusicAdapter implements BrowsesCatalog`.

- [ ] **Step 1: Record fixtures.** Run once (network, anonymous) and trim each payload to ≤ 6 items per list, keeping these real gap cases: a song with `artists: []` ("Engel (Live in Mexico City)"), a song without `album`, artist `songs` without `duration`, the "Top result" artist without `browseId`, one `episode` and one `podcast` entry:
  `php -r 'require "vendor/autoload.php"; $m = new Ytmusicapi\YTMusic(); file_put_contents("tests/Fixtures/YouTubeMusic/catalog-search.json", json_encode($m->search("Rammstein", limit: 20), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));'` and the same with `get_artist("UCs6GGpd9zvsYghuYe0VDFUQ")` → `catalog-artist.json`, `get_album("MPREb_LJSVi8szMQL")` → `catalog-album.json`.
- [ ] **Step 2: Write the failing mapper tests** (load fixtures with `json_decode(..., false)`):
  - `it maps the top result artist from its artists list` → `topArtist->ref->externalId === 'UCs6GGpd9zvsYghuYe0VDFUQ'`, `name === 'Rammstein'`, `subscribers === '43.3M'`.
  - `it keeps songs, albums, artists and playlists and drops episodes, podcasts and videos`.
  - `it maps a song without credited artists with an empty artist list` (title "Engel (Live in Mexico City)", `artists === []`, `durationSeconds === 275`).
  - `it maps a search song without album to a null album`.
  - `it returns empty sections for a query with only episodes`.
  - `it maps an artist page` → `monthlyListeners === '43.3M'`, `views === 8401443283`, 5 top tracks with `durationSeconds === null`, albums with `year`, `related[0]->name === 'OOMPH!'`.
  - `it maps an album with its plays` → `trackCount === 11`, `durationSeconds === 2640`, `playsByVideoId['t-NUBR2kufw'] === 36000000`, tracks carry the album ref.
- [ ] **Step 3: Run** — Expected: FAIL.
- [ ] **Step 4: Implement the mapper methods**, reusing private `thumbnail()`, `ref()`, `string()`, `artists()`, `track()`. Parse counts like "36M plays" / "8,401,443,283 views" with one private `count(?string): ?int` (K = 1e3, M = 1e6, B = 1e9; digits with separators otherwise). Album duration from `duration_seconds`. Then the adapter methods delegate gateway → mapper (`publicPlaylist` reuses `mapper->playlist()`).
- [ ] **Step 5: Add adapter tests** `it searches the catalog without a cookie` (gateway call recorded with `cookie === ''`) and `it reads an album`.
- [ ] **Step 6: Run** both files — Expected: PASS. PHPStan.
- [ ] **Step 7: Commit** `feat(catalog): map youtube music search, artist and album pages`

### Task 4: Cached catalog actions

**Files:**
- Create (via `make:action`): `app/Actions/SearchCatalog.php`, `DescribeArtist.php`, `DescribeAlbum.php`, `DescribePublicPlaylist.php`, `CountAlbumTracks.php`
- Test: `tests/Unit/Actions/CatalogActionsTest.php`

**Interfaces:**
- Consumes: `ProviderRegistry::require(Provider::YouTubeMusic, BrowsesCatalog::class)`.
- Produces: `SearchCatalog::handle(string $query, ?CatalogFilter $filter = null, int $limit = 20): CatalogResults`; `DescribeArtist::handle(string $artistId): CatalogArtist`; `DescribeAlbum::handle(string $albumId): CatalogAlbum`; `DescribePublicPlaylist::handle(string $playlistId): RemotePlaylist`; `CountAlbumTracks::handle(list<string> $albumIds, bool $cachedOnly = false): array<string, int>` (missing/failed ids left out; reuses `DescribeAlbum`'s cache; `$cachedOnly` never calls the provider, for the palette).

- [ ] **Step 1: Write the failing tests:** `it counts only cached albums when asked` (no provider call), `it caches a search for ten minutes per normalized query` (`"  RAMMSTEIN "` then `"rammstein"` → one adapter call; after `travel(11)->minutes()` → two), `it caches an album for a day`, `it skips albums that fail to load when counting tracks` (one id unavailable → result has only the other), `it returns empty results for a blank query without calling the provider`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** with `Cache::remember()`; keys `catalog:search:{sha1(normalized query)}:{filter}:{limit}`, `catalog:artist:{id}`, `catalog:album:{id}`, `catalog:playlist:{id}`; normalization `Str::of($q)->squish()->lower()`. DTOs must serialize in the cache (readonly classes of scalars/arrays/enums only).
- [ ] **Step 4: Run** — Expected: PASS. PHPStan.
- [ ] **Step 5: Commit** `feat(catalog): cache catalog searches and pages`

### Task 5: Library, listening and genre overlays

**Files:**
- Create (via `make:action`): `app/Actions/MatchLibraryTracks.php`, `DescribeArtistInLibrary.php`, `ArtistGenres.php`
- Test: `tests/Unit/Actions/CatalogOverlaysTest.php`

**Interfaces:**
- Consumes: existing `TrackGenres::handle(list<string> $videoIds, int $limit = 2): array<string, list<string>>`.
- Produces:
  - `MatchLibraryTracks::handle(User $user, list<RemoteTrack> $tracks): array<int, bool>` — index in `$tracks` → in library; match by video id, else by normalized `title` + first artist against the user's account tracks.
  - `DescribeArtistInLibrary::handle(User $user, string $artistName): ArtistInLibraryData` with `trackCount`, `albumCount`, `listenCount`, `listenedMinutes`, `firstListenedAt`, `lastListenedAt`, `topTracks` (list of `{title, listens}`, max 4); artist match: `artists` equal to the name or starting with `"{name},"`, case-insensitive.
  - `ArtistGenres::handle(string $artistName, int $limit = 4): list<string>` — strongest `contributor_tags` genre names (`tags.is_genre`) for the contributor with that name.

- [ ] **Step 1: Write the failing tests:** `it flags catalog tracks the library holds by video id`, `it falls back to title and artist when the video id differs` ("Benzin"/"Rammstein" stored with another id → true), `it never flags another user's library`, `it counts an artist's tracks, albums and listens` (3 tracks on 2 albums, 4 listens totalling 600 s → 10 minutes, top track first), `it does not count "Rammstein Live Recordings" as Rammstein`, `it returns an artist's strongest genres`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** with factories for `Track`, `Playlist`, `YouTubeMusicAccount`, `Listen`, `Contributor`, `Tag` (check existing factory states first). `ArtistInLibraryData` is a spatie Data class in `app/Data` with `#[TypeScript]`.
- [ ] **Step 4: Run** — Expected: PASS. PHPStan.
- [ ] **Step 5: Commit** `feat(catalog): overlay library, listens and genres on catalog data`

### Task 6: Page data for the front

**Files:**
- Create in `app/Data` (all `#[TypeScript]`): `CatalogTrackData.php`, `CatalogAlbumData.php`, `CatalogArtistData.php`, `CatalogPlaylistData.php`, `CatalogVideoData.php`, `SearchResultsData.php`, `ArtistPageData.php`, `AlbumPageData.php`, `PublicPlaylistPageData.php`
- Test: `tests/Unit/Data/CatalogPageDataTest.php`

**Interfaces:**
- Produces:
  - `CatalogTrackData extends TrackData` adding `?string $artistId`, `?string $albumId`, `?int $year`, `?int $plays`, `bool $inLibrary`; `static fromRemote(RemoteTrack $track, list<string> $genres, bool $inLibrary, ?int $plays = null): self` (duration formatted `m:ss`, thumbnail through `ThumbnailProxy::url()`).
  - `CatalogAlbumData(string $id, string $title, string $type, ?int $year, string $artists, ?string $artistId, ?string $thumbnailUrl, ?int $trackCount)` — `trackCount` null until counted (deferred).
  - `CatalogArtistData(string $id, string $name, ?string $thumbnailUrl, ?string $subscribers)`, `CatalogPlaylistData(string $id, string $title, ?string $author, ?int $trackCount, ?string $thumbnailUrl)`, `CatalogVideoData(?string $videoId, string $title, ?string $thumbnailUrl, ?int $views)`.
  - `SearchResultsData(?CatalogArtistData $topArtist, ?ArtistInLibraryData $topArtistInLibrary, list<CatalogTrackData> $topArtistLibraryTracks, list<CatalogTrackData> $tracks, list<CatalogAlbumData> $albums, list<CatalogArtistData> $artists, list<CatalogPlaylistData> $playlists)`.
  - `ArtistPageData(CatalogArtistData $artist, ?string $monthlyListeners, ?int $views, ?string $description, list<string> $genres, list<CatalogTrackData> $tracks, list<CatalogAlbumData> $albums, list<CatalogAlbumData> $singles, list<CatalogVideoData> $videos, list<CatalogArtistData> $related, ArtistInLibraryData $inLibrary)`.
  - `AlbumPageData(CatalogAlbumData $album, ?string $description, ?int $durationSeconds, int $totalPlays, list<string> $genres, int $inLibraryCount, list<CatalogTrackData> $tracks)`.
  - `PublicPlaylistPageData(CatalogPlaylistData $playlist, ?string $description, list<CatalogTrackData> $tracks)`.

- [ ] **Step 1: Write the failing tests:** `it formats a catalog track like a library track` (215 s → `"3:35"`, proxied thumbnail, `isAvailable` true), `it keeps an unknown duration null`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement**, then `php artisan typescript:transform` to regenerate `resources/js/types/generated.d.ts`.
- [ ] **Step 4: Run** tests — PASS; `bun run test:types` — PASS.
- [ ] **Step 5: Commit** `feat(catalog): add catalog page data`

### Task 7: Controllers and routes

**Files:**
- Create: `app/Http/Controllers/SearchController.php` (`index`, `suggestions`), `ArtistController.php` (`show`), `AlbumController.php` (`show`), `BrowsePlaylistController.php` (`show`)
- Create (via `make:action`): `app/Actions/BuildSearchResults.php`, `BuildArtistPage.php`, `BuildAlbumPage.php`
- Modify: `routes/web.php` (inside the `auth`, `verified` group)
- Test: `tests/Feature/Controllers/SearchControllerTest.php`, `ArtistControllerTest.php`, `AlbumControllerTest.php`, `BrowsePlaylistControllerTest.php`

**Interfaces:**
- Consumes: Tasks 4–6.
- Produces routes (all with `throttle:catalog`, suggestions with `throttle:catalog-suggestions`): `search.index` GET `/search` (`q`, `type` ∈ CatalogFilter values), `search.suggestions` GET `/search/suggestions` (`q`) → JSON `SearchResultsData` with at most 1 top artist, 3 tracks, 2 albums, 1 artist, 1 playlist, album `trackCount` from `CountAlbumTracks(…, cachedOnly: true)`; `artist.show` GET `/artists/{artistId}`; `album.show` GET `/albums/{albumId}`; `browse-playlist.show` GET `/browse/playlists/{playlistId}`.
- Inertia components and props: `search/Index` → `query: string`, `type: string|null`, `results` (deferred `SearchResultsData`), `albumTrackCounts` (deferred `array<string,int>`); `artist/Show` → `page` (`ArtistPageData`), `albumTrackCounts` (deferred); `album/Show` → `page` (`AlbumPageData`), `moreByArtist` (deferred list of `CatalogAlbumData`); `browse-playlist/Show` → `page` (`PublicPlaylistPageData`). On `ProviderException` the page prop becomes `null` and `catalogError: string` is set (translated message).
- `BuildAlbumPage` release info: year only; label and release date are not shown in part 1 (no stored source).

- [ ] **Step 1: Write the failing feature tests** (FakeProviderAdapter builders): `it renders search results for a query`, `it renders an empty search` (blank `q` → component with `results` null, no provider call), `it narrows results to one type`, `it returns palette suggestions as json`, `it marks tracks already in the library`, `it shows a catalog error instead of failing` (adapter `unavailable` → 200 with `catalogError`), `it renders an artist with library stats and genres`, `it renders an album with total plays and in-library count`, `it redirects a public playlist already in the library to its library page`, `it requires authentication` (each route → login redirect), `it throttles suggestions per user` (121st call in a minute → 429, another user still 200), `it renders a catalog error when the provider budget is spent` (adapter `rateLimitedFor = 30` → 200 with `catalogError`).
- [ ] **Step 2: Run** the four files — Expected: FAIL.
- [ ] **Step 3: Implement** the builders (compose actions; genres via `TrackGenres` on video ids; the search top artist's library tracks: up to 3 of the user's tracks by that artist, most recently added; artist top tracks without duration take it from a cached album holding the same video id, else stay null) and thin controllers. Define the `catalog` and `catalog-suggestions` limiters with `RateLimiter::for()` in `AppServiceProvider` (`Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())`, same with 120), add routes with `->middleware('throttle:catalog')` / `'throttle:catalog-suggestions'`; run `php artisan route:list --path=search` and confirm the middleware.
- [ ] **Step 4: Run** — Expected: PASS. Pint + PHPStan on all touched PHP.
- [ ] **Step 5: Commit** `feat(catalog): add search, artist, album and public playlist routes`

### Task 8: Shared track row, genre tags and library marker

**Files:**
- Create: `resources/js/components/music/TrackRow.vue`, `GenreTags.vue`, `InLibraryMark.vue`
- Modify: `resources/js/pages/playlist/Show.vue` (use `TrackRow`, no visual change)
- Test: `resources/js/components/music/TrackRow.browser.test.ts`

**Interfaces:**
- Produces `TrackRow` props: `track: App.Data.TrackData | App.Data.CatalogTrackData`, `position: number`, `current: boolean`, `playing: boolean`, `showAlbum?: boolean` (default true), `showArtwork?: boolean` (default true), `showArtist?: boolean` (default true), `showPlays?: boolean` (default false); emits `tap(event: MouseEvent)`, `play()`. Renders `GenreTags` (≤ 2 dimmed pills after the artist) and `InLibraryMark` when `inLibrary` (a lucide `LibraryBig` glyph in `text-faint`, `title` + `aria-label` "In your library"; no text pill). Artist/album become Wayfinder links to `ArtistController.show` / `AlbumController.show` when `artistId`/`albumId` exist.

- [ ] **Step 1: Write the failing browser test:** `renders genre tags and the library mark`, `hides the cover and album when asked`, `emits play from the play button without tapping`.
- [ ] **Step 2: Run** `bun run test resources/js/components/music/TrackRow.browser.test.ts` — Expected: FAIL.
- [ ] **Step 3: Implement** by extracting the row markup and classes from `playlist/Show.vue` (keep its CSS grid); swap the playlist page to it.
- [ ] **Step 4: Run** the test and `tests/Feature/Controllers/PlaylistControllerTest.php` — PASS; check `/playlists/{id}` in the browser looks unchanged.
- [ ] **Step 5: Commit** `refactor(music): extract the shared track row with genre tags`

### Task 9: Sort and group-by-album composables

**Files:**
- Create: `resources/js/composables/useCatalogSort.ts`, `resources/js/composables/useAlbumGrouping.ts`, `resources/js/components/music/SortControl.vue`, `resources/js/components/music/GroupToggle.vue`, `resources/js/components/music/AlbumGroup.vue`
- Test: `resources/js/composables/useCatalogSort.test.ts`, `resources/js/composables/useAlbumGrouping.test.ts`

**Interfaces:**
- Produces:
  - `type CatalogSort = 'recent' | 'oldest' | 'popular' | 'az'`; `useCatalogSort(storageKey: string)` → `{ sort: Ref<CatalogSort>, apply<T>(items: T[], yearOf: (item: T) => number | null, titleOf: (item: T) => string): T[] }`; default `'recent'`, persisted with `useLocalStorage('sonder.sort.' + storageKey)`.
  - `useAlbumGrouping()` → `{ grouped: Ref<boolean>, group(tracks: App.Data.CatalogTrackData[]): { albumId: string | null, title: string, tracks: App.Data.CatalogTrackData[] }[] }`; persisted under `sonder.grouped`; tracks without album go to one group titled by `trans('Singles')`; group order = first appearance in the (already sorted) list.
  - `SortControl` (styled native `<select>` with sort icon; labels "Newest", "Oldest", "Popular", "A → Z"), `GroupToggle` ("List" / "By album"), `AlbumGroup` (header: cover, title link, type · year · dimmed track count, "N results"; rows via `TrackRow` with `showArtwork=false`, `showAlbum=false`).

- [ ] **Step 1: Write the failing tests:** `sorts newest first by default`, `keeps provider order for equal years`, `unknown years sort last`, `sorts A to Z with French collation` (`['Zeit', 'Ängste', 'Adieu']` → `['Adieu', 'Ängste', 'Zeit']`), `popular keeps the given order`, `groups tracks by album in sorted order`, `puts tracks without album under Singles`.
- [ ] **Step 2: Run** `bun run test resources/js/composables/useCatalogSort.test.ts resources/js/composables/useAlbumGrouping.test.ts` — FAIL.
- [ ] **Step 3: Implement** (stable sort on `[year == null, ±year, index]`).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(catalog): sort catalog sections and group tracks by album`

### Task 10: Search page

**Files:**
- Create: `resources/js/pages/search/Index.vue`, `resources/js/components/catalog/SearchSpotlight.vue`, `resources/js/components/catalog/AlbumCard.vue`, `resources/js/components/catalog/ArtistCard.vue`, `resources/js/components/catalog/CompactRow.vue`, `resources/js/components/catalog/CatalogError.vue`
- Modify: `resources/js/components/shell/MobileTabBar.vue`, `resources/js/components/shell/AppSidebar.vue` (search entry opens the palette on desktop, `/search` on mobile)
- Modify: `lang/fr_BE.json`, `lang/en_US.json`

**Interfaces:**
- Consumes: Task 7 props, Tasks 8–9 components.
- Produces: `AlbumCard` props `album: App.Data.CatalogAlbumData`, `trackCount?: number | null` (dimmed " · N tracks"; small skeleton while `undefined`); `ArtistCard` props `artist: App.Data.CatalogArtistData`; `CompactRow` props `kind: 'album' | 'artist' | 'playlist'`, `item`, `trackCount?`; `CatalogError` props `message: string` with a retry button (`router.reload()`).

- [ ] **Step 1: Build the page** to match the prototype's search variant B: search field (updates `q` with `router.get(SearchController.index({ query: { q } }), { preserveState: true, replace: true })`, debounced 300 ms), sticky chips (All/Tracks/Albums/Artists/Playlists → `type`), `SearchSpotlight` when `topArtist` (tinted with its blurred picture, grain, Play/Shuffle/See artist, right half "In your library" with counts and up to 3 rows), else an "In your library" section; two columns: Tracks (`SortControl`, `GroupToggle`, `TrackRow` with `useTrackTap` and origin `'search'`) and Albums/Artists/Playlists `CompactRow`s; single-type layouts for a chip; skeletons in `<Deferred>`; `CatalogError`; empty state "Nothing found for “:query”".
- [ ] **Step 2: Add a feature assertion** in `SearchControllerTest`: component `search/Index` receives `query`.
- [ ] **Step 3: Verify in the browser** (`/search?q=rammstein`, desktop and 390 px): matches the prototype, no horizontal scroll, keyboard focus visible, track click queues with the flight, Play plays. Run `bun run test:types` and `bun run test:lint`.
- [ ] **Step 4: Commit** `feat(catalog): add the search page`

### Task 11: Command palette with catalog results and preview

**Files:**
- Modify: `resources/js/components/shell/CommandPalette.vue`
- Create: `resources/js/composables/useCatalogSuggestions.ts`, `resources/js/composables/useRecentSearches.ts`, `resources/js/components/shell/PalettePreview.vue`
- Test: `resources/js/composables/useCatalogSuggestions.test.ts`, `resources/js/composables/useRecentSearches.test.ts`

**Interfaces:**
- Produces: `useCatalogSuggestions(query: Ref<string>)` → `{ results: Ref<App.Data.SearchResultsData | null>, loading: Ref<boolean>, failed: Ref<boolean> }`, using Inertia v3 `useHttp` against `SearchController.suggestions`, 200 ms debounce, cancels the previous request, ignores responses for older queries, no request under 2 characters. `useRecentSearches()` → `{ items: Ref<RecentItem[]>, add(item: RecentItem): void, remove(id: string): void }`, `RecentItem = { id: string, kind: 'query' | 'artist' | 'album', label: string, sub?: string, artwork?: string | null }`, max 8, newest first, deduplicated, stored under `sonder.search.recent` with try/catch.

- [ ] **Step 1: Write the failing tests:** `waits for two characters`, `ignores a response for an older query`, `keeps library results when suggestions fail` (`failed` true, `results` null), `keeps eight recent items newest first`, `moves a repeated item to the top`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement**, then extend the palette: width `min(880px, calc(100% - 32px))`; groups In your library → Top result (large row with play) → Tracks → Albums → Artists → Playlists, catalog rows with skeletons while loading and "Catalog unavailable" when failed; `PalettePreview` (≥ 860 px) for the selected item (top artist: picture + the suggestion tracks credited to them; album: cover, title, type · year · track count when known; track/playlist: artwork + the Enter hint); footer hints ↑↓, ↵ (open / queue next), ⇧↵ play now, ⌘↵ all results → `SearchController.index`; empty input shows Recent then Go to / Actions; selecting artist/album adds to recents.
- [ ] **Step 4: Run** tests — PASS; browser check: typing "ramm" fast, arrows across groups, ⌘↵, Esc.
- [ ] **Step 5: Commit** `feat(catalog): search the catalog from the command palette`

### Task 12: Artist page

**Files:**
- Create: `resources/js/pages/artist/Show.vue`, `resources/js/components/catalog/ArtistAside.vue`, `resources/js/components/catalog/VideoCard.vue`

**Interfaces:**
- Consumes: `ArtistPageData`, `albumTrackCounts`, Tasks 8–10 components.

- [ ] **Step 1: Build** per prototype artist variant B: ambient tint, round picture, Kicker, Display name, listeners · subscribers · views (`Intl.NumberFormat` compact, current locale), artist genre tags, actions Play / Shuffle / ⋯ in one row with 10 px gaps (Radio hidden: not in scope); main column Tracks (sort + group), Discography tabs All / Albums / Singles & EP + sort, Clips & lives (`VideoCard`, 16:9; click plays the video id), Similar artists; sticky aside "Chez toi" (counts, minutes, first/last listen with `useRelativeTime`, top tracks) and About (description clamped to 4 lines, "Read more", source "YouTube Music"); hide About facts that are null; aside stacks under content below 860 px.
- [ ] **Step 2: Verify** in the browser at desktop and 390 px against the prototype; `bun run test:types`, `bun run test:lint`; `ArtistControllerTest` still passes.
- [ ] **Step 3: Commit** `feat(catalog): add the artist page`

### Task 13: Album and public playlist pages

**Files:**
- Create: `resources/js/pages/album/Show.vue`, `resources/js/pages/browse-playlist/Show.vue`, `resources/js/components/catalog/AlbumFacts.vue`

**Interfaces:**
- Consumes: `AlbumPageData`, `moreByArtist`, `PublicPlaylistPageData`.

- [ ] **Step 1: Build the album page** per prototype album variant B: sticky left column (cover 280 px, Kicker from `type`, title, artist link · year · N tracks · duration); actions on two rows `[Play][Shuffle, fills][⋯]` then full-width disabled "Save as playlist" (tooltip "Coming soon"), all 40 px high with 10 px gaps; `AlbumFacts` (duration · tracks, total plays, "In your library: N of M", genre tags, description clamped with "Read more"; label and release date omitted); right: header row (#, Title, Plays, ⏱) and `TrackRow` with `showArtwork=false`, `showAlbum=false`, `showArtist` only when it differs from the album artist, `showPlays`; then "More by …" `AlbumCard`s (deferred). Below 860 px: single column, plays column hidden.
- [ ] **Step 2: Build the public playlist page**: playlist-style header (artwork tint, Kicker "Playlist", title, author · N tracks), Play / Shuffle, `TrackRow` list.
- [ ] **Step 3: Verify** both in the browser (desktop + 390 px) against the prototype; `bun run test:types`, `bun run test:lint`; controller tests pass.
- [ ] **Step 4: Commit** `feat(catalog): add the album and public playlist pages`

### Task 14: Final pass

**Files:**
- Modify: `lang/fr_BE.json`, `lang/en_US.json` (any missing keys)

- [ ] **Step 1:** Run the touched suites: `./vendor/bin/pest --tia --parallel tests/Unit/Services/Music tests/Unit/Actions tests/Unit/Data tests/Feature/Controllers` and `bun run test`; `bun run test:types`; `bun run test:lint`; Pint and PHPStan on every touched PHP file. All green.
- [ ] **Step 2:** Run `/impeccable polish` on search, artist and album pages; fix findings that contradict DESIGN.md.
- [ ] **Step 3:** Browse the real flow logged in: ⌘K "Apashe" → artist → album → play a track, queue another, back to search; check the browser console with Boost `browser-logs`.
- [ ] **Step 4:** Commit any fixes `fix(catalog): polish catalog pages`, then ask the owner to run `php artisan test --compact`.
