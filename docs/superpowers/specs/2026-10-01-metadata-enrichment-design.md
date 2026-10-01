# Metadata enrichment (sub-project D)

## Problem

Sonder knows a track only by what YouTube Music says about it: a title, an
artist string, an album name, a duration and a video id. The next big step,
the suggestion engine, needs much more: genres and tags, who wrote, produced
and played on a recording, which recordings are similar, how popular they
are, and stable identifiers to recognise the same recording across
providers. The user also wants to see the audio quality of what plays.

None of this exists today. `tracks` are per-playlist copies deleted and
recreated by every sync, so enrichment has nothing durable to attach to.

## Goals

- Resolve every track of the library to a recording known to the registries
  (a MusicBrainz recording id and/or an ISRC).
- Collect, per recording: identity (ISRC, ISWC, release date), credits
  (songwriters, publishers, producers, engineers, performers), genres and
  tags with a weight per source, Last.fm listeners and play count, and
  similar recordings.
- Collect, per main artist: genres and tags.
- Collect, per playable source: the best available audio format, its
  loudness and whether it is a music video or an audio track.
- Keep every raw response so new fields can be extracted later without
  calling the services again.
- Run in the background over the whole library, without slowing down syncs,
  listens or YouTube Music itself.
- Show it in an "Info" tab of the right panel.
- Make the pipeline observable: metrics, traces, logs, a Grafana dashboard
  and an alert.

## Non-goals

- The suggestion engine itself, contributor pages, discovery by connections.
- Works (ISWC grouping covers and versions), listener statistics over time.
- Contributing data back to credits.fm.
- Discogs, Deezer, and YouTube Music's own credits (`get_song_credits` costs
  two calls per track on the scarcest budget). All can be added later.
- Lossless, hi-res and spatial formats: YouTube never offers them. Tidal's
  adapter adds those columns when it exists.
- Knowing the audio format actually played. The YouTube IFrame player picks
  it and does not expose it; Sonder shows the best format available.

## Decisions taken during design

| Question | Decision |
|---|---|
| Order with plan 2 | Enrich now. `recordings` and `contributors` are the **seed of the catalogue**: plan 2 grows them into `tracks` and `artists` (rename plus columns) instead of creating parallel tables. The multi-provider spec is amended accordingly. Never two entities for the same thing. |
| Sources | credits.fm (ISRC, credits), MusicBrainz (identity, genres, tags), Last.fm (tags, similar, listeners), YouTube Music itself (audio format). |
| Storage | Hybrid: raw responses in `enrichments`, plus structured tables for what will be queried. |
| Scope and delivery | Three plans, each shippable alone: D1 data foundation, D2 more sources, D3 interface and dashboards (see Delivery). |
| Where the info shows | A tab of the right panel, next to "Up next": the current track by default, any playlist row through an info button. |
| Rate limiting | The existing pattern: a rate-limited decorator spending a named limiter's budget, refusing over budget with a `retryAfter`, the job releasing itself. |
| Observability | First-class: metrics, spans and logs per source, a provisioned Grafana dashboard and an alert. |

## What the sources return (verified 2026-10-01)

- **credits.fm**, public read routes, no key:
  - `POST /v1/resolve/batch` takes up to 50 `{name, artist}` pairs and
    returns the ISRC per row. **It returns an ISRC even for nonsense** (a
    made-up title and artist got `USJKL0700030`), so every ISRC is verified.
  - `GET /v1/isrc/{isrc}?contribute=false` returns everything per
    recording in one call: `recording_title`, `artist_names`,
    `recording_artists` (with MBIDs), `iswc`, `release_date`,
    `songwriters[]` (name, IPI, role, publishers), `performers[]` (name,
    MBID, role, attributes, credit_type), `upcs`, and `duration` (often
    null). `POST /v1/resolve/track` is not needed.
  - No genres, no audio format. Fuzzy name matching, no duration input.
  - A 503 from credits.fm means its database is down, not a rate limit.
- **MusicBrainz**, 1 request/s per IP, a descriptive User-Agent required
  (a 503 means over the limit):
  - `GET /ws/2/isrc/{isrc}?inc=artist-credits` returns a **list** of
    recordings, each with its length (ms), or 404. The ISRC resource
    rejects `inc=genres`.
  - `GET /ws/2/recording/{mbid}?inc=genres+tags+artist-credits+isrcs`
    gives genres and tags with vote counts, `first-release-date` and all
    ISRCs.
  - `GET /ws/2/recording?query=…` (search fallback, with a score).
  - `GET /ws/2/artist/{mbid}?inc=genres+tags`.
- **Last.fm**, at most 5 requests/s averaged over 5 minutes, free key:
  - `track.getTopTags` and `artist.getTopTags` give weights from 0 to 100.
    `track.getInfo` only gives tag names, but also listeners and play count.
  - `track.getSimilar` returns similar tracks.
  - Always called with `autocorrect=1`. Last.fm's own MBIDs are not trusted.
- **YouTube Music**, through ytmusicapi `get_song(videoId)` with the user's
  cookie: `streamingData.adaptiveFormats` (codec, bitrate, `audioQuality`,
  sample rate, channels, `loudnessDb`) and `videoDetails.musicVideoType`.
  Verified: four audio formats came back for a test video, the best being
  AAC itag 140 at about 130 kbps and 44.1 kHz.

## Design

### Schema

All keys are UUIDs; PostgreSQL; every foreign key used for a reverse lookup
gets an explicit index. Columns in a unique key are `NOT NULL`.

| Table | Columns | Notes |
|---|---|---|
| `recordings` | `id`, `mbid` (nullable, unique), `isrc` (nullable, index), `iswc` (nullable), `title`, `artist_name`, `duration_seconds` (nullable), `release_date` (date, nullable), `lastfm_listeners` (nullable), `lastfm_playcount` (nullable), timestamps | Becomes plan 2's `tracks`. CHECK `mbid IS NOT NULL OR isrc IS NOT NULL`. `isrc` is not unique: one ISRC can cover several MusicBrainz recordings, and plan 2 merges on it. Lookup order: by `mbid`, then by `isrc` among rows without an `mbid`. |
| `recording_resolutions` | `id`, `provider`, `external_id`, `recording_id` (nullable FK, null on delete), `status` (`resolved`, `not_found`, `failed`), `method` (nullable: `credits_fm`, `musicbrainz_search`), `confidence` (nullable decimal 0–1), `query_title`, `query_artist`, `attempts`, `resolved_at` (nullable), `next_attempt_at` (nullable), timestamps | Which recording a provider's track is. Unique `(provider, external_id)`; indexes `recording_id`, `(status, next_attempt_at)`. Becomes plan 2's `track_sources`, whose `match_method` / `match_score` it already mirrors. |
| `source_audio_qualities` | `id`, `provider`, `external_id`, `codec`, `bitrate_kbps`, `sample_rate_hz`, `channels`, `loudness_db` (nullable), `video_kind` (nullable: `music_video`, `audio`), `checked_at`, timestamps | Best available format of one playable source. Unique `(provider, external_id)`. Its columns move onto `track_sources` in plan 2. |
| `contributors` | `id`, `name`, `normalized_name`, `mbid` (nullable, unique), `ipi` (nullable, unique), timestamps | People, groups and publishers. Becomes plan 2's `artists` (which gain `ipi`). Matched by MBID, then IPI, then an existing name-only contributor with the same `normalized_name`, then created. Index `normalized_name`. |
| `recording_contributors` | `id`, `recording_id` (FK, cascade), `contributor_id` (FK, cascade), `credit_type` (`artist`, `songwriter`, `publisher`, `producer`, `performer`), `role` (not null, default `''`; the source's wording: `mix`, `vocal`, `ComposerLyricist`…), `attributes` (JSON), `source`, timestamps | Unique `(recording_id, contributor_id, credit_type, role, source)`; index `contributor_id`. Plan 2's `artist_track` covers only `artist` credits; this table keeps the rest. |
| `tags` | `id`, `name`, `slug` (unique), `is_genre`, timestamps | `slug` normalises case, spaces and hyphens (`Hip-Hop` = `hip hop`). `is_genre` is true when MusicBrainz lists the name as a genre. |
| `recording_tags` | `recording_id` (FK, cascade), `tag_id` (FK, cascade), `source`, `weight` (0–100) | Primary key `(recording_id, tag_id, source)`; index `tag_id`. MusicBrainz weights are each vote count over the highest; Last.fm's are its own 0–100 counts. |
| `contributor_tags` | `contributor_id` (FK, cascade), `tag_id` (FK, cascade), `source`, `weight` | Same shape. Track-level tags are often sparse, so artist tags are the suggestion engine's fallback. |
| `similar_recordings` | `id`, `recording_id` (FK, cascade), `title`, `artist_name`, `match` (0–1), `source`, timestamps | Mostly outside the library, so stored as text with no foreign key. Index `recording_id`. |
| `enrichments` | `id`, `subject_type` (`recording`, `contributor`, `source`), `subject_key`, `source` (enum `MetadataSource`), `endpoint` (e.g. `resolve`, `isrc`, `isrc_lookup`, `top_tags`, `info`, `similar`, `song`), `status` (`done`, `not_found`, `failed`), `attempts`, `payload` (JSON, nullable), `error` (nullable), `fetched_at` (nullable), `next_attempt_at` (nullable), timestamps | One row per subject, source and endpoint: the raw truth. Unique `(subject_type, subject_key, source, endpoint)`; index `(status, next_attempt_at)`. `subject_key` is a recording or contributor id, or `provider:external_id` for resolution and quality calls (made before a recording exists). |

### Object architecture

Raw gateways plus pure mappers, as for YouTube Music: Actions depend on the
gateway **interfaces** (they must store raw payloads), mappers turn payloads
into Data objects for decisions and projection.

```
app/Enums/MetadataSource.php             CreditsFm, MusicBrainz, LastFm, YouTubeMusic
app/Enums/EnrichmentStatus.php           Done, NotFound, Failed
app/Support/MusicText.php                normalisation shared with plan 2's matching:
                                         titles (strip "(Official Video)", "[Lyrics]",
                                         keep version markers), "Artist - Title" split,
                                         " - Topic" channels, artist names
app/Exceptions/Metadata/                 MetadataSourceRateLimited (retryAfter),
                                         MetadataSourceUnavailable
app/Services/Metadata/
├── CallBudget.php   spends a named limiter (shared by every decorator)
├── Data/       TrackQuery, RegistryRecording, Credit, WeightedTag
├── CreditsFm/   {CreditsFmGateway, HttpCreditsFmGateway, RateLimitedCreditsFmGateway, CreditsFmMapper}
├── MusicBrainz/ {…Gateway, Http…, RateLimited…, MusicBrainzMapper}
└── LastFm/      {…Gateway, Http…, RateLimited…, LastFmMapper}   (D2)
app/Services/Music/Contracts/DescribesAudioQuality.php   implemented by the YouTube Music adapter
```

- Each source mirrors the YouTube Music layering: a raw gateway (Laravel HTTP
  client, timeouts, status codes mapped to typed exceptions per source), a
  rate-limited decorator, and a source class mapping payloads into Data
  objects.
- `MetadataSourceRateLimited` is separate from `ProviderRateLimited`: it is
  never shown to a user, so it has no translated `ProviderErrorCode`. It has
  the same shape (`retryAfter`).
- YouTube Music gains `song()` on its gateway and implements
  `DescribesAudioQuality`, so Tidal can implement it later.
- An `arch()` test keeps Actions on contracts only, as for YouTube Music.

**Actions** (no suffix, `app/Actions`): `ResolveRecordings` (a batch of
provider tracks), `DescribeRecording` (one recording, one source),
`DescribeContributor` (one contributor, one source),
`DescribeSourceQuality` (one provider track), `ProjectEnrichment` (raw
payloads of one recording into the structured tables).

**Jobs** (named for what they do, like `SyncYouTubeMusicLibrary`):
`ResolveLibraryTracks`, `EnrichRecording`, `EnrichContributor`,
`CheckSourceQuality`, `ProjectRecordingMetadata`.

### Rate limiting

Same mechanism as `RateLimitedGateway`: a decorator spends a named limiter's
budget before each call and throws `MetadataSourceRateLimited` with
`availableIn()` when over budget. A 429 (and a 503 from MusicBrainz) becomes
the same exception, using `Retry-After`. The job catches it and
`release()`s itself; nothing sleeps in a worker.

| Limiter | Budget | Why |
|---|---|---|
| `credits-fm` | 2/s, 3 000/h | No documented limit; conservative. |
| `musicbrainz` | 1/s | Their published rule. |
| `lastfm` | 4/s | Under their 5/s average. |
| `youtube-music` (existing, per account) | 30/min, 500/h, shared with syncs | Background calls go through a new `RateLimitedGateway` method that refuses them while fewer than 10 calls remain in the minute window or 150 in the hour, so a sync always has budget. |

The audio quality of a source is global, but YouTube needs a cookie: the
check uses the account of a user whose library contains that track, the most
recently verified first.

### Jobs, retries and ordering

- All jobs run on the `enrichment` queue, processed by the existing worker
  (queues `default,enrichment`, so user-facing work goes first) with a
  single process. One process keeps releases from churning against the
  per-source limits.
- Like `SyncYouTubeMusicLibrary`, jobs use `retryUntil()` (2 h) and
  `maxExceptions` (3), so releases for a rate limit never count as
  failures. `backoff()` is `[60, 600, 3600]`.
- When a job gives up, its row is set to `failed` with
  `next_attempt_at = now + 1 day`. `not_found` gets 30 days. Done data is
  refreshed after 90 days.
- Projection uses `insertOrIgnore` then a re-select (the plan 2 rule:
  PostgreSQL aborts a transaction on a unique violation).
  `ProjectRecordingMetadata` has a `WithoutOverlapping` lock per recording.

**The chain**, for each provider track:

1. `ResolveLibraryTracks` (chunks of 50):
   - credits.fm `resolve/batch`.
   - Each ISRC is checked with MusicBrainz `isrc` lookup (404 = unknown). Among its
     recordings, keep the one whose length is within 5 s of the source's
     duration and whose normalised artist matches. Result: MBID, confidence
     1.0.
   - No such recording: credits.fm `isrc` detail; keep the ISRC with
     confidence 0.6 when its normalised title and artist match the query,
     otherwise reject.
   - No ISRC: MusicBrainz recording search, accepted with a score of at
     least 90, the same artist and a duration within 5 s (confidence 0.9).
   - Nothing: `not_found`.
   - Upserts the recording and the resolution. Then dispatches
     `EnrichRecording` once per source, and `CheckSourceQuality` (D2).
2. `EnrichRecording(recording, source)` stores the raw payloads:
   - credits.fm (recording has an ISRC): `isrc` detail (credits, ISWC,
     release date).
   - MusicBrainz (recording has an MBID): `recording` lookup (genres, tags,
     artists, ISRCs, first release date).
   - Last.fm (D2): `getInfo`, `getTopTags`, `getSimilar`.
   - Then dispatches `ProjectRecordingMetadata(recording)`.
3. `ProjectRecordingMetadata` rebuilds the structured rows of that recording
   from all its payloads. For each main artist with an MBID that has no
   description yet, it dispatches `EnrichContributor`.
4. `EnrichContributor(contributor, source)`: MusicBrainz and (D2) Last.fm
   artist tags, then projects the contributor's tags.
5. `CheckSourceQuality` (D2): YouTube Music `get_song`. Keeps the best audio
   format (highest `audioQuality`, then bitrate) with loudness and video
   kind.

**Triggers.**
- `metadata:enrich {--refresh}`: the whole library.
- After a library sync completes, the tracks without a resolution. The hook
  sits in the `SyncYouTubeMusicLibrary` job today and moves to plan 2's
  import.
- The existing daily schedule runs a `metadata:retry-due` command for due
  `failed`, `not_found` and stale rows. It stays daily so a scale-to-zero
  environment is not kept awake.

**Call counts** for the first run (about 1 256 tracks, about 600 main
artists):
- credits.fm: about 26 batch calls, plus about 1 256 `isrc` details. At
  2/s, about 10 min, in parallel with MusicBrainz.
- MusicBrainz: about 1 256 ISRC lookups, about 1 256 recording lookups, a
  few hundred searches and about 600 artist lookups. At 1/s, about 1 h 15.
- Last.fm: about 3 800 track calls and about 600 artist calls. At 4/s, about
  20 min.
- YouTube Music: 1 256 `get_song` calls at about 350/h after the reserve,
  about 3.6 h, spread in the background.

### Observability

keepsuit/laravel-opentelemetry exports to Grafana Cloud as elsewhere.
`HttpClientInstrumentation` traces every outbound call and
`QueueInstrumentation` every job.

Metrics:

| Name | Type | Attributes |
|---|---|---|
| `sonder.enrichment.lookups` | counter | `source`, `endpoint`, `outcome` (`found`, `not_found`, `failed`) |
| `sonder.enrichment.lookup.duration` | histogram, seconds (stored by Mimir as `…_seconds`) | `source`, `endpoint` |
| `sonder.enrichment.resolutions` | counter | `method`, `outcome`, `confidence` (bucketed: `high`, `medium`) |
| `sonder.enrichment.coverage` | gauge, ratio 0–1 | `facet` (`resolved`, `credits`, `genres`, `similar`, `audio_quality`) |
| `sonder.enrichment.backlog` | gauge | `status` (`failed`, `not_found`, `unresolved`) |
| `sonder.rate_limit.refusals` | counter | `limiter` (also wired into the existing YouTube Music gateway) |

Coverage and backlog are computed from the database when a
`metadata:enrich` or `metadata:retry-due` run ends, and recorded with the
synchronous `Meter::gauge()`. Dashboard queries use
`last_over_time(...[1d])` so a sparse gauge does not flicker.

Spans carry `sonder.enrichment.source`, `.endpoint`, `.outcome` and the
subject key. Logs: one warning per `failed` lookup (source, endpoint, error)
and one info summary per run.

The dashboard "Sonder · Enrichment" is provisioned through the Grafana MCP
and versioned in `docs/observability/dashboards/enrichment.json`. It shows:
- coverage per facet over time;
- lookups per source and outcome;
- p50 and p95 latency per source;
- rate-limit refusals per limiter;
- the backlog;
- the latest failure logs;
- a link to the enrichment queue's traces.

The alert fires when a source has at least 20 lookups and more than half of
them `failed` (not `not_found`) over 30 minutes.

### Front end

- The right panel gets two tabs, "Up next" and "Info". Info shows the
  current track by default; an info button on a playlist row opens it for
  that track.
- The Info tab shows:
  - identity: ISRC and release date;
  - genres, then tags, as chips ordered by weight;
  - credits grouped by type (artists, songwriters, producers, performers);
  - Last.fm listeners;
  - the five closest similar recordings;
  - the audio format line, e.g. "AAC · 130 kbps · 44.1 kHz · best available
    on YouTube".
- A small format badge appears in the player bar.
- States: still resolving ("Looking for this track…"), not found, partial.
- Data comes from `GET /tracks/{provider}/{externalId}/info` (`Provider`
  enum binding, JSON `TrackInfoData`), called through the Wayfinder
  controller object with `useHttp` when the tab opens, and cached per track
  for the session.
- **Authorisation.** The track must be in the user's library (a `tracks` row
  of one of their playlists with that video id), in their saved queue, or in
  their listens. Otherwise the response is 404, so nothing reveals whether
  another library has it. The route changes shape in plan 2, when tracks get
  their own ids.

### Configuration

`config/services.php`: `lastfm.key` and `musicbrainz.user_agent`
(`Sonder/<version> ( <contact> )`). Without a Last.fm key, that source is
skipped and logged once.

## Delivery

Three plans, each shippable and useful alone:

- **D1, data foundation.**
  - The tables it fills (`similar_recordings` and `source_audio_qualities`
    come with D2).
  - `MusicText`.
  - credits.fm and MusicBrainz sources with their limiters.
  - Resolution, recording and contributor description, projection.
  - The commands and the post-sync hook.
  - Metrics, spans and logs.
- **D2, more sources.**
  - Last.fm (tags, similar, listeners).
  - YouTube Music audio format, with the `DescribesAudioQuality` capability
    and the background reserve on `RateLimitedGateway`.
- **D3, seeing it.**
  - The Info tab, the player bar badge and the info endpoint.
  - The Grafana dashboard and the alert.

## Testing

- Each HTTP gateway against `Http::fake()` with recorded fixtures (real
  responses captured once), including status code mapping per source.
- Source mappers: unit tests on fixtures (tag weights, duplicate credits
  collapsed, best audio format chosen, ISRC with several recordings).
- `MusicText`: a dataset of real messy titles from the library.
- Actions and jobs: feature tests with fake sources. They cover:
  - idempotence;
  - each resolution rule and its confidence;
  - not found and backoff;
  - a rate-limited release that does not count as an attempt;
  - the YouTube Music reserve;
  - concurrent projection.
- Endpoint: authentication, a track outside the user's library returns 404,
  the three states.
- Arch: Actions never use a concrete source; only `Services\Metadata\*`
  knows the HTTP gateways.
- Info tab: a browser component test of its states.

## Risks

- **YouTube formats.** YouTube increasingly withholds `streamingData` from
  unofficial clients (PO tokens). It worked on 2026-10-01; if it stops, the
  quality step records `not_found` and the panel hides the line.
- **credits.fm.** A young service with no documented limit or SLA. The
  MusicBrainz search fallback keeps resolution working without it.
- **Messy YouTube titles.** Resolution depends on `MusicText`. The
  `resolved` coverage gauge shows on the dashboard where it falls short.
