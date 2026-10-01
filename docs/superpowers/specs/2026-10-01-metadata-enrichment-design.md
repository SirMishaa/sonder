# Metadata enrichment (sub-project D)

## Problem

Sonder knows a track only by what YouTube Music says about it: a title, an
artist string, an album name, a duration and a video id. The next big step,
the suggestion engine, needs much more: genres and tags, who wrote, produced
and played on a recording, which recordings are similar, how popular they
are, and stable identifiers to recognise the same recording across
providers. The user also wants to see the audio quality of what plays.

None of this exists today. `tracks` are per-playlist copies deleted and
recreated by every sync, so enrichment has nothing durable to attach to until
the catalogue of plan 2 (multi-provider foundation) lands.

## Goals

- Resolve every track of the library to a recording known to the registries
  (ISRC, or a MusicBrainz recording id when no ISRC is found).
- Collect, per recording: identity (ISRC, ISWC, release date), credits
  (songwriters, publishers, producers, engineers, performers), genres and
  tags with a weight per source, Last.fm listeners and play count, and
  similar recordings.
- Collect, per main artist: genres and tags.
- Collect, per playable source: the best available audio quality, its
  loudness and whether it is a music video or an audio track.
- Keep every raw response so new fields can be extracted later without
  calling the services again.
- Run in the background over the whole library, without ever slowing down
  syncs, listens or YouTube Music itself.
- Show it in an "Info" tab of the right panel.
- Make the pipeline observable: metrics, traces, logs and a Grafana dashboard.

## Non-goals

- The suggestion engine itself, contributor pages and discovery by
  connections (next sub-project, built on this data).
- Works (ISWC grouping covers and versions), listener statistics over time,
  publishers as their own entity.
- Contributing data back to credits.fm (`POST /v1/contribute`).
- Discogs and Deezer. They can be added later as further sources.
- Knowing the audio format actually played. The YouTube IFrame player picks
  it and does not expose it; Sonder shows the best format available.

## Decisions taken during design

| Question | Decision |
|---|---|
| Order with plan 2 | Enrich now. Everything is keyed by universal identifiers (ISRC, MBID) or by `(provider, external_id)`, never by the current `tracks`. Plan 2's catalogue links to it through its `isrc` / `mbid` columns. |
| Sources | credits.fm (identity, credits), MusicBrainz (genres, tags, MBIDs), Last.fm (tags, similar, listeners), YouTube Music itself (audio quality, credits fallback). |
| Storage | Hybrid: raw responses in `enrichments`, plus structured tables for what will be queried. |
| First scope | Background pipeline and data, plus an Info tab. Contributor pages wait for the suggestion engine. |
| Where the info shows | A tab of the right panel, next to "Up next": the current track by default, any playlist row through an info button. |
| Rate limiting | The existing pattern: a rate-limited decorator spending a named limiter's budget, refusing over budget with a `retryAfter`, the job releasing itself. |
| Observability | First-class: metrics, spans and logs per source, a provisioned Grafana dashboard and one alert. |

## What the sources return (verified 2026-10-01)

- **credits.fm** `POST /v1/resolve/track {name, artist, contribute: false}`,
  no key needed: `isrc`, `iswc`, `match_status`, `songwriters[]` (name, IPI,
  role, publishers), `performers[]` (name, MBID, role, attributes,
  credit_type), `sources`. `GET /v1/isrc/{isrc}` adds `release_date` and
  `upcs`. No genres, no audio quality.
- **MusicBrainz** `GET /ws/2/isrc/{isrc}?inc=genres+tags+artist-credits`,
  `GET /ws/2/recording?query=…` as a search fallback, and
  `GET /ws/2/artist/{mbid}?inc=genres+tags`. One request per second, a
  descriptive User-Agent required.
- **Last.fm** `track.getInfo` (listeners, playcount, top tags),
  `track.getSimilar`, `artist.getTopTags`. Free API key.
- **YouTube Music** through ytmusicapi `get_song(videoId)`: `streamingData.adaptiveFormats`
  with codec, bitrate, `audioQuality`, sample rate, channels and
  `loudnessDb`, plus `videoDetails.musicVideoType`. Verified with the user's
  cookie: four audio formats came back for a test video (best: AAC 140 at
  ~130 kbps, 44.1 kHz). `get_song_credits` gives YouTube Music's own credits
  (needs the `MPTC…` browse id from `get_watch_playlist`).

## Design

### Schema

All keys are UUIDs; PostgreSQL; every foreign key used for a reverse lookup
gets an explicit index.

| Table | Columns | Notes |
|---|---|---|
| `recordings` | `id`, `isrc` (nullable, unique), `mbid` (nullable, unique), `iswc` (nullable), `title`, `artist_name`, `release_date` (date, nullable), `lastfm_listeners` (nullable), `lastfm_playcount` (nullable), timestamps | A recording as the registries know it. At least one of `isrc` / `mbid` is set. Plan 2's `tracks` gain a `recording_id` found through these. |
| `recording_resolutions` | `id`, `provider`, `external_id`, `recording_id` (nullable FK, null on delete), `status` (`resolved`, `not_found`, `failed`), `method` (nullable: `credits_fm`, `musicbrainz_search`), `query_title`, `query_artist`, `resolved_at` (nullable), `attempts`, timestamps | Which recording a provider's track is. Unique `(provider, external_id)`; index `recording_id`. Stands in for plan 2's `track_sources` and is dropped once they exist. |
| `source_audio_qualities` | `id`, `provider`, `external_id`, `codec`, `bitrate_kbps`, `sample_rate_hz`, `bit_depth` (nullable), `channels`, `spatial` (nullable, e.g. `dolby_atmos`), `loudness_db` (nullable), `video_kind` (nullable: `music_video`, `audio`), `checked_at`, timestamps | Best format available for one playable source. Unique `(provider, external_id)`. Moves onto `track_sources` in plan 2. |
| `contributors` | `id`, `name`, `mbid` (nullable, unique), `ipi` (nullable, unique), timestamps | People and groups. Matched by MBID, then IPI, then created. Plan 2's `artists` link through `mbid`. |
| `recording_contributors` | `id`, `recording_id` (FK, cascade), `contributor_id` (FK, cascade), `credit_type` (`artist`, `songwriter`, `publisher`, `producer`, `performer`), `role` (source wording: `mix`, `vocal`, `ComposerLyricist`…), `attributes` (JSON), `source`, timestamps | Unique `(recording_id, contributor_id, credit_type, role, source)`; index `contributor_id`. Duplicates in a response are collapsed. |
| `tags` | `id`, `name`, `slug` (unique), `is_genre`, timestamps | `slug` normalises case, spaces and hyphens (`Hip-Hop` = `hip hop`). `is_genre` is true when MusicBrainz lists it as a genre. |
| `recording_tags` | `recording_id` (FK, cascade), `tag_id` (FK, cascade), `source`, `weight` (0–100) | Primary key `(recording_id, tag_id, source)`; index `tag_id`. Weights are normalised per source (vote count over the highest, Last.fm's count as is). |
| `contributor_tags` | `contributor_id` (FK, cascade), `tag_id` (FK, cascade), `source`, `weight` | Same shape. The fallback when a recording has few tags of its own. |
| `similar_recordings` | `id`, `recording_id` (FK, cascade), `title`, `artist_name`, `mbid` (nullable), `match` (0–1), `source`, timestamps | Mostly outside the library, hence text, no foreign key. Index `recording_id`. |
| `enrichments` | `id`, `subject_type` (`recording`, `contributor`, `source`), `subject_key`, `source` (enum `MetadataSource`), `status` (`pending`, `done`, `not_found`, `failed`), `attempts`, `payload` (JSON, nullable), `error` (nullable), `fetched_at` (nullable), `next_attempt_at` (nullable), timestamps | One row per subject and source, the raw truth. Unique `(subject_type, subject_key, source)`; index `(status, next_attempt_at)`. `subject_key` is the recording or contributor id, or `provider:external_id` for a source. |

### Object architecture

```
app/Enums/MetadataSource.php            CreditsFm, MusicBrainz, LastFm, YouTubeMusic
app/Enums/EnrichmentStatus.php          Pending, Done, NotFound, Failed
app/Exceptions/Metadata/                MetadataSourceRateLimited (retryAfter),
                                        MetadataSourceUnavailable
app/Services/Metadata/
├── Contracts/
│   ├── ResolvesRecordings.php          resolve(title, artist, ?durationSeconds): ?ResolvedRecording
│   ├── DescribesRecordings.php         describe(Recording): RecordingDescription
│   └── DescribesContributors.php       describe(Contributor): ContributorDescription
├── Data/                               ResolvedRecording, RecordingDescription,
│                                       ContributorDescription, Credit, WeightedTag, SimilarRecording
├── TitleCleaner.php                    strips "(Official Video)", "[Lyrics]", "- Topic", splits "Artist - Title"
├── CreditsFm/   {CreditsFmGateway, HttpCreditsFmGateway, RateLimitedCreditsFmGateway, CreditsFmSource}
├── MusicBrainz/ {…Gateway, Http…, RateLimited…, MusicBrainzSource}
└── LastFm/      {…Gateway, Http…, RateLimited…, LastFmSource}
app/Services/Music/Contracts/
├── DescribesAudioQuality.php           audioQuality(credentials, externalId): ?AudioQuality
└── ReadsTrackCredits.php               credits(credentials, externalId): list<Credit>
```

- Each source mirrors the YouTube Music layering: a raw gateway (Laravel
  HTTP client, timeouts, typed exceptions), a rate-limited decorator, and a
  source class mapping payloads into the shared Data objects.
- YouTube Music gains `song()`, `watchPlaylist()` and `songCredits()` on its
  gateway (through the existing `RateLimitedGateway`) and implements the two
  new capability interfaces, so Tidal can implement them later.
- Actions depend on contracts only, enforced by an `arch()` test like the
  YouTube Music one.

### Rate limiting

Same mechanism as `RateLimitedGateway`: each decorator spends a named
limiter's budget before a call and throws `MetadataSourceRateLimited` with
`availableIn()` when over budget. The job catches it and `release()`s itself
for that many seconds. Nothing ever sleeps in a worker. A 429 or 503 from a
service is turned into the same exception, using `Retry-After`.

| Limiter | Budget | Why |
|---|---|---|
| `credits-fm` | 2/s, 3 000/h | No documented limit; conservative. |
| `musicbrainz` | 1/s | Their published rule. |
| `lastfm` | 4/s | Under their 5/s guidance. |
| `youtube-music` (existing, per account) | 30/min, 500/h, shared | Enrichment calls keep a reserve: they are refused while fewer than 150 calls remain in the hour, so a sync always has budget. |

### Pipeline

Queue `enrichment`, separate from the default queue. Every job is idempotent
and checks its `enrichments` row first.

1. **`ResolveRecording`** (provider, external id, title, artists, duration):
   cleans the title, asks credits.fm. Found: upserts the recording by ISRC
   and stores the credits.fm payload (it already holds the credits). Not
   found: MusicBrainz recording search, accepted only with score ≥ 90, the
   same normalised artist and a duration within 5 s. Nothing reliable:
   `not_found`, retried after 30 days.
2. **`DescribeRecording`** per source: MusicBrainz (genres, tags, recording
   and artist MBIDs), Last.fm (listeners, play count, top tags, 20 similar),
   credits.fm `GET /v1/isrc` (release date) when resolved by ISRC.
   YouTube Music credits only when credits.fm had none.
3. **`DescribeContributor`** for main artists only (credit type `artist`):
   MusicBrainz and Last.fm artist tags.
4. **`DescribeSourceQuality`** per playable source: YouTube Music `get_song`,
   keeping the best audio format (highest `audioQuality`, then bitrate).
5. **`ProjectEnrichment`**: turns raw payloads into the structured tables.
   Re-runnable on its own with `metadata:reproject`.

Triggers:
- After a playlist sync: tracks without a resolution are dispatched.
- `metadata:enrich {--refresh}`: the whole library (first run ≈ 1 h,
  MusicBrainz-bound; the YouTube Music quality step spreads over several
  hours because of its shared hourly budget).
- A nightly scheduled sweep: due `failed` rows (backoff 1 min, 10 min, 1 h,
  6 h, 24 h; given up after 5 attempts until the next refresh) and data
  older than 90 days.

### Observability

Built with keepsuit/laravel-opentelemetry, exported to Grafana Cloud like
the rest of Sonder. Outbound calls go through Laravel's HTTP client, so
`HttpClientInstrumentation` traces them; `QueueInstrumentation` traces jobs.

Metrics:

| Name | Type | Attributes |
|---|---|---|
| `sonder.enrichment.lookups` | counter | `source`, `step`, `outcome` (`found`, `not_found`, `failed`, `rate_limited`) |
| `sonder.enrichment.lookup.duration` | histogram (s) | `source`, `step` |
| `sonder.enrichment.resolutions` | counter | `method`, `outcome` |
| `sonder.enrichment.coverage` | gauge (ratio 0–1) | `facet` (`resolved`, `credits`, `genres`, `similar`, `audio_quality`) |
| `sonder.enrichment.backlog` | gauge | `status` (`pending`, `failed`) |
| `sonder.rate_limit.refusals` | counter | `limiter` (also wired into the existing YouTube Music gateway) |

Coverage and backlog are computed from the database by a scheduled job every
5 minutes and recorded as gauges (a PHP request cannot host an observable
callback).

Spans: each step adds attributes `sonder.enrichment.source`, `.step`,
`.outcome` and the subject key. Logs: one warning per `failed` lookup with
source, step and error; one info summary per `metadata:enrich` run.

Dashboard "Sonder · Enrichment", provisioned through the Grafana MCP and
kept as JSON in `docs/observability/dashboards/enrichment.json`:
coverage per facet over time, lookups per source and outcome, p50/p95
latency per source, rate-limit refusals per limiter, backlog, the latest
failure logs, and a link to the traces of the enrichment queue.

Alert: a source failing more than half of its lookups over 30 minutes.

### Front end

- The right panel gets two tabs, "Up next" and "Info". Info shows the
  current track by default; an info button on a playlist row opens it for
  that track.
- Info contents: identity (ISRC, release date), genres then tags as chips
  (weight shown by order), credits grouped by type (artists, songwriters,
  producers, performers), Last.fm listeners, the five closest similar
  recordings, and the audio quality line (e.g. "AAC · 130 kbps · 44.1 kHz ·
  best available on YouTube").
- A small quality badge in the player bar.
- States: not resolved yet ("Looking for this track…"), not found, partial.
- Data comes from `GET /tracks/{provider}/{externalId}/info` (JSON,
  `TrackInfoData`), fetched with `useHttp` when the tab opens, cached per
  track for the session.

### Configuration

`config/services.php`: `lastfm.key`, `musicbrainz.user_agent`
(`Sonder/<version> ( <contact> )`), `credits_fm.key` (optional). Missing
Last.fm key: that source is skipped, logged once.

## Testing

- Each HTTP gateway against `Http::fake()` with recorded fixtures (real
  responses captured once, including the credits.fm one above).
- Source mappers: unit tests on fixtures (tag weights, duplicate credits
  collapsed, best audio format chosen).
- `TitleCleaner`: a dataset of real messy titles from the library.
- Actions and jobs: feature tests with fake sources (idempotence, not found,
  backoff, rate-limited release, YouTube Music reserve).
- Endpoint: authentication, a track outside the user's library is refused,
  the three states.
- Arch: actions never use a concrete source; only `Services\Metadata\*`
  knows the HTTP gateways.
- Info tab: a browser component test for its states.

## Risks

- **YouTube formats.** YouTube increasingly withholds `streamingData` from
  unofficial clients (PO tokens). It worked on 2026-10-01; if it stops, the
  quality step records `not_found` and the panel hides the line.
- **credits.fm availability.** A young service without a documented limit
  or SLA; the MusicBrainz fallback keeps resolution working without it.
- **Messy YouTube titles.** Resolution quality depends on `TitleCleaner`;
  unresolved tracks are measured by the coverage gauge, so the dashboard
  shows where to improve.
