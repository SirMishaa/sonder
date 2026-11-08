# Catalog search, artist & album pages — design (part 1 of 3)

## Intent

Today ⌘K only filters what Sonder already holds (your playlists, the queue,
pages, actions). Searching "Apashe" shows only your own tracks; there is no
way to browse an artist or an album the way any music app allows. This part
makes Sonder a place to *explore* the whole YouTube Music catalog, not just
replay the library — and to show, everywhere, what the enrichment pipeline
already knows (genres, plays, your own listening).

Success: type "Rammstein", see the artist, their albums and tracks, open the
artist page, open an album, play a track or queue it — without leaving Sonder.

## The three parts (agreed order)

1. **This document**: catalog search, artist / album / public-playlist pages, play & queue.
2. Context menu (right click on tracks and playlists): play, play next, add to queue, open artist/album, later "add to playlist" / "save as playlist".
3. Local playlist editing + explicit push to YouTube Music; on conflict, a diff where each change can be discarded track by track.

Each part gets its own design and plan. Part 1 must not block 2 and 3:
catalog pages render tracks through one shared row component, so the context
menu attaches in one place later.

## Visual reference (approved)

The approved mockup is `.impeccable/prototype/search.html` (variant **B** on
every screen), fed with real anonymous ytmusicapi data in
`.impeccable/prototype/rammstein.js` and the owner's library. Serve the
folder and open it to compare while building. DESIGN.md ("The Listening
Room") rules all visual decisions. Build with the `impeccable` skill.

## Goals

### Palette (⌘K), variant B "list + preview"

- Wider palette (≈880 px): results list on the left, a **preview pane** on the right for the selected item (artist: picture + top tracks; album: cover + tracklist; track/playlist: artwork + "Enter to queue / open").
- Groups, in order: **In your library** (current local matching), **Top result** (large row with a play button), **Tracks**, **Albums** (with dimmed track count), **Artists**, **Playlists**.
- Footer hints: ↑↓ navigate, ↵ open (or "queue next" on a track), ⇧↵ play now on a track, **⌘↵ "All results for …"** which opens /search.
- Empty input: **Recent** (searches and opened items, each removable), then Go to / Actions as today.
- Phone width: preview pane hidden.

### /search page, variant B "tinted block + dense columns"

- Search field, then sticky **filter chips** All / Tracks / Albums / Artists / Playlists with counts.
- **Spotlight block** for the top result: tinted by its own artwork (blurred image, faded into the page, grain), artist picture, Display-size name, listeners · subscribers, Play / Shuffle / "See artist". Right half of the block: **"In your library"** for that result (count of tracks and albums, three rows, link to the rest). This replaces a separate library section when the top result is an artist; for other queries, an "In your library" section (playlists and tracks) stays on top.
- Below, two columns: **Tracks** (left) and **Albums / Artists / Playlists** (right, compact rows).
- A chip switches to a single-type view with more results.

### Artist page, variant B (enriched)

- Ambient tint from the artist picture; round picture; Kicker "Artist"; Display name; meta: monthly listeners · subscribers · views; **genre tags** (artist-level); actions Play / Shuffle / Radio / ⋯ with even 10 px spacing.
- Main column: **Tracks** (sort + group by album), **Discography** with tabs All / Albums / Singles & EP and sort, **Clips & lives** (16:9 thumbnails, view counts), **Similar artists**.
- Sticky right column:
  - **"Chez toi"**: tracks and albums of this artist in your library, your listen count, listening time and first/last listen, your most played tracks of theirs (from `listens`).
  - **About**: origin, active since, members (when known), bio clamped to 4 lines with "Read more" and its source.

### Album page, variant B "sticky cover"

- Left sticky column: cover, Kicker (Album / Single / EP), title, meta (artist link · year · track count · duration).
- **Actions laid out on two rows**: [Play] [Shuffle — fills the row] [⋯], then a full-width "Save as playlist" (disabled until part 3). All 40 px high, 10 px gaps.
- **Facts**: release date, duration · track count, total plays (sum of track play counts), label, "In your library: N of M tracks"; **genre tags**; description clamped to 4 lines with "Read more" and source.
- Right: tracklist with a header row (#, Title, Plays, ⏱). Rows show number, title, "In your library" badge, artist only when it differs from the album artist (features), **genre tags**, play count, duration. No per-row cover (it's the page's). Then "More by <artist>".

### Shared behaviors

- **Track click**: same as everywhere today (`useTrackTap`): nothing playing → plays; something playing → queued next with the flight; double click replaces; clicking the playing track toggles. The row's **Play button** plays directly: the track, then the rest of the visible list.
- **Genre tags on every track row** (search, artist, album, spotlight, library rows): up to two Last.fm genre tags, small dimmed pills after the artist. Shown whenever enrichment knows the recording; nothing when it doesn't.
- **Track count on every album** (cards, compact rows, palette): "Album · 2022 · 11 tracks", the count dimmed (Faint Text).
- **Sort** on every track and album section: **Newest first (default)**, Oldest, Popular (YTM order), A → Z. Ties keep YTM's popularity order. Titles say "Tracks", not "Popular tracks", since popularity isn't the default.
- **Group by album** switch ("List / By album") on track sections: groups headed by cover, album title, type · year · dimmed track count, and "N results"; group order follows the sort; singles grouped together; clicking a header opens the album; per-row covers hidden inside groups.
- "In your library" marker on catalog tracks that the library holds: **an icon, not the text pill of the mockup** (owner's feedback); e.g. a small library/check glyph in Faint Text next to the title, with a tooltip and an `aria-label`. Exact glyph and placement left to the builder.
- Artists and albums on rows link to their pages when the catalog gives an id.
- Every load gets a skeleton matching the layout; content reveals with the 40 ms stagger.

## Non-goals (this part)

- Context menu (part 2). Adding to playlists, saving an album as a playlist, any write to YouTube Music (part 3).
- Radio button: rendered, wired to YTM's radio playlist only if trivial; otherwise hidden until a later part *(builder may decide)*.
- Following artists, liking albums. Podcasts and episodes.

## Anti-goals

- Anything that reads as a Spotify clone or a generic search page; amber only for action and "now playing".
- Centered spinners. Burning the user's YouTube Music cookie on browsing traffic. Changing how library playlists or the queue behave.

## Constraints

- Laravel 13 / Inertia v3 / Vue, Action pattern, Wayfinder controller objects (`.ai/rules/js.md`), PHPStan level 8, Pest `--tia --parallel`, Pint, Octane (no request state in singletons).
- Catalog access behind a provider **capability contract** (like `ReadsPlaylists`) implemented by `YouTubeMusicAdapter`; only `YtmusicapiGateway` talks to ytmusicapi.
- ytmusicapi 1.12.2: `search`, `get_search_suggestions`, `get_artist`, `get_artist_albums`, `get_album`, `get_playlist`, `get_watch_playlist` all work **anonymously** (verified 2026-11-08 with a cookieless `YTMusic`).

## YouTube Music data: what it gives and what it doesn't (verified)

| Need | Source | Gap & handling |
|---|---|---|
| Search results | `search(q)`: top result (artist id in `artists[0].id`, no `browseId`), songs, albums, artists, playlists, videos, episodes, podcasts | Filter to songs/albums/artists/playlists (+videos for later). Some songs come with an empty `artists` list: fall back to the query's top artist only when the title matches, else show the title alone. |
| Song duration | search songs have it | **Artist top songs have none**: fill from the search payload or the album when known; otherwise show "—". |
| Song album | artist top songs and album tracks: yes | **Some search songs (lives, singles) have no album**: they sort last by date and group under "Singles" until resolved. |
| Album track count | `get_album` only | **Not in search or artist payloads**: one `get_album` per visible album, cached 24 h, loaded deferred (skeleton on the count). |
| Album year / type | search & artist payloads | — |
| Release date, label | not provided (only inside the Wikipedia description) | From MusicBrainz enrichment when available; otherwise hide the fact. |
| Play counts | album tracks (`playCount`), videos (`views`) | Total album plays = sum of tracks. |
| Artist stats & bio | `get_artist`: monthlyListeners, subscribers, views, description, songs, albums, singles, videos, related | Members / origin / active since come from MusicBrainz when known; else hide. |
| Genres | none from YTM | From Sonder's enrichment: `recording_tags` (tracks), `contributor_tags` (artists), Last.fm genre tags (`tags.is_genre`). |

## How it works

### Backend

- **Contract** `App\Services\Music\Contracts\BrowsesCatalog`: `search`, `artist`, `album`, `publicPlaylist`; new DTOs in `App\Services\Music\Data` (reuse `RemoteTrack`, `RemoteArtist`, `RemoteAlbum` where they fit).
- **Gateway**: `search`, `artist`, `artistAlbums`, `album` on `YouTubeMusicGateway`; implemented in `YtmusicapiGateway` with a **cookieless** `YTMusic`; mapped in `YouTubeMusicMapper`; through `RateLimitedGateway`; traced like existing calls.
- **Caching**: search 10 min per normalized query + filter; artist, album, public playlist 24 h; album track counts 24 h. Keys hold no user data.
- **Library overlay** (per user, not cached with catalog data): which catalog tracks are in the library (match by video id, then normalized title + artist), the artist's track/album counts in the library, listen stats and most played (from `listens`), "N of M tracks" on albums.
- **Enrichment overlay**: genre tags for each displayed track (recording match by normalized title + artist, top two genre tags) and artist (contributor tags), release date / label / members from MusicBrainz when stored. Part 1 only *reads* existing enrichment; it doesn't trigger new enrichment for catalog-only tracks *(my call; on-demand enrichment of browsed tracks can come later)*.
- **Actions**: `SearchCatalog`, `DescribeArtist`, `DescribeAlbum`, `DescribePublicPlaylist`, plus overlay actions (names settled at plan time).
- **Routes** (auth + verified):
  - `GET /search` → `SearchController@index` (`search/Index`, `q`, `type`, results deferred with skeleton).
  - `GET /search/suggestions?q=` → JSON for the palette, via Inertia v3 `useHttp`, debounced ~200 ms, previous request cancelled.
  - `GET /artists/{id}` → `ArtistController@show` (`artist/Show`).
  - `GET /albums/{id}` → `AlbumController@show` (`album/Show`).
  - `GET /browse/playlists/{youtubePlaylistId}` → public playlist page; redirects to the library page when the playlist is in the user's library.
- **Data to the front**: catalog tracks as `App\Data\TrackData` (so player, `toQueueTrack`, `useTrackTap` work unchanged) extended with optional `artistId`, `albumId`, `year`, `plays`, `genres` (≤2), `inLibrary`. Thumbnails through `ThumbnailProxy`.
- **Errors**: provider unavailable → inline error state with retry on pages; in the palette, "Catalog unavailable" under the library group, which keeps working.

### Frontend

- `CommandPalette.vue`: widened with the preview pane; library groups local as today; catalog groups from suggestions with skeleton rows while loading; keyboard navigation across all groups.
- **Recent searches**: last 8 queries and opened items in `localStorage` (`sonder.search.recent`), per device *(my call)*.
- **Shared track row** extracted from `pages/playlist/Show.vue` and used by library and catalog pages; supports genre tags, "in library" badge, optional album column, hidden cover (album page, groups).
- Composables for **sort** and **group by album** shared across sections; sort and grouping choices remembered per device *(my call)*.
- Pages: `search/Index.vue`, `artist/Show.vue`, `album/Show.vue`, `browse-playlist/Show.vue`.
- Mobile (< 860 px): columns stack, preview pane hidden, play-count column hidden, artist aside below the content, chips scroll inside their row.
- i18n: every string through `$t` / `trans` (fr + en).

## Testing

- Feature tests per controller and the suggestions endpoint with a fake `YouTubeMusicGateway`: results rendered, filters, library-id redirect, provider failure → error state, auth required, library and enrichment overlays (in-library flags, counts, genres).
- Mapper unit tests from recorded payload fixtures (search, artist, album), including the gaps above (missing artists, durations, albums).
- Vitest: palette merge and keyboard index, sort (newest first, ties, unknown years last), grouping, recent searches.
- Cache: second call doesn't hit the gateway.

## Left to the builder

- Exact DTO shapes and action names; debounce and limits; skeleton details.
- Whether the public playlist DTO reuses `RemotePlaylist`; whether `TrackData` is extended or a `CatalogTrackData` extends it.
- Radio button wiring or hiding.
