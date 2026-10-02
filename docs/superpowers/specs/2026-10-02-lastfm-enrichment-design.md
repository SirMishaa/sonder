# Last.fm enrichment (sub-project D2a)

Part of [metadata enrichment](2026-10-01-metadata-enrichment-design.md).
D2 is split: **D2a** Last.fm (this spec), **D2b** YouTube Music audio format
(unchanged from the parent spec), **D2c** an audio features spike (tempo,
energy, valence, danceability; AcousticBrainz's frozen dump keyed by MBID or a
live API), decided later.

## Problem

The suggestion engine comes next, with a mood mode: one click on "play",
"focus" or "chill" builds a playlist on the fly. A mood is read from what
describes a track, and today Sonder has almost nothing of that kind:

- MusicBrainz genres cover few tracks (42% of recordings have a genre of
  their own, 70% through their artist).
- 23% of the library (194 tracks not found, 98 pending of 1 256) resolves to
  no recording, so it gets no metadata at all.
- Nothing measures popularity, nothing says which tracks sound alike, and
  nothing follows the charts.

Last.fm's folksonomy is the richest free vocabulary of moods and genres
(`chill`, `energetic`, `melancholic`, `gothic rock`…), with weights, plus
listener counts, similar tracks and artists, and charts.

## Goals

- Describe every recording and every main artist with Last.fm: weighted tags,
  similar tracks or artists, listeners and play count.
- Resolve, through Last.fm, the tracks that credits.fm and MusicBrainz cannot,
  so that no library track stays without metadata.
- Keep every popularity reading, so a trend can be computed later.
- Take a daily snapshot of four charts: global, Belgium, France, United
  States.
- Shape the data for the mood engine without building it.

## Non-goals

- The suggestion engine and the mood taxonomy (which tags make "chill").
- Mood candidate pools from `tag.getTopTracks`: the engine asks them on
  demand.
- A user's own Last.fm history (scrobbles, loved tracks): no account to
  connect yet.
- Audio features (D2c) and YouTube Music audio formats (D2b).
- The Info tab and the Grafana dashboard (D3).

## Decisions taken during design

| Question | Decision |
|---|---|
| Unresolved tracks | Last.fm is the last resolution step. A track it knows becomes a recording identified by its normalised title and artist. The rule "an MBID or an ISRC" is dropped, as plan 2's `tracks` will drop it anyway. |
| Which recordings Last.fm describes | All of them, whatever their identifier. Last.fm is asked by artist and title, never by MBID. |
| Extras | `artist.getSimilar`, a popularity history, daily chart snapshots. |
| Charts followed | Global (`chart.getTopTracks`), Belgium, France, United States (`geo.getTopTracks`), top 200 each. |
| Cadence | Popularity (`getInfo`) weekly, tags and similar every 90 days, charts daily. |
| Retention | Popularity samples and chart snapshots are kept indefinitely: about 100 000 samples (10 MB) and 290 000 chart entries (40 MB) a year, and Last.fm offers no history to rebuild a lost reading. Pruning can come later. |
| Tag noise | Keep at most 20 tags per subject, weight ≥ 5. The raw answer stays in `enrichments`, so the filter can change without calling again. |

## What Last.fm returns (verified 2026-10-02)

Recorded in `tests/Fixtures/Metadata/lastfm-*.json` (lists trimmed to five
entries). Every call sends `api_key`, `format=json` and `autocorrect=1`.

- **Errors** come back as `{"error": 6, "message": "Track not found"}` with
  HTTP 200 (6: not found; 29: rate limit exceeded; 10, 26: key invalid or
  suspended; 11, 16: service temporarily unavailable).
- **`track.getInfo`**: corrected `name` and `artist.name`, `listeners`,
  `playcount` (strings), `duration` **in milliseconds, often `"0"`**, the five
  top tags **without weights**, an album and an `mbid` (not trusted). It knows
  almost every track MusicBrainz misses: "Still Here" by League of Legends
  (73 945 listeners), "Habits" by "to love" (15 listeners).
- **`track.getTopTags`**: up to 100 tags, `count` from 0 to 100. Niche tracks
  have none (Hippocampe Fou, "Le Mal du pays"), so artist tags are the
  fallback. Fandom tags appear (`banana fish` at 100, `satoru`).
- **`track.getSimilar`**: up to `limit` tracks with `match` (0–1), `playcount`,
  `duration` in seconds and the artist; the same artist's tracks are included.
- **`artist.getInfo`**: `stats.listeners`, `stats.playcount`, five tags,
  five similar artists, a biography.
- **`artist.getTopTags`**: weighted like tracks. Lord of the Lost: `Gothic
  Rock` 100, `Gothic Metal` 79, `german` 30, `glam rock` 29, `industrial
  metal` 5.
- **`artist.getSimilar`**: up to `limit` artists with `match` (0–1).
- **`chart.getTopTracks`** and **`geo.getTopTracks`** (`country` is the
  English country name): `limit=200` is honoured in one call. Entries carry
  `name`, `artist.name`, `duration` in seconds, `listeners`, and for the
  global chart `playcount`. The rank is the position in the list.

## Design

### Schema

| Change | Details |
|---|---|
| `recordings` | Drop the `recordings_identified` CHECK. Add `match_title` and `match_artist` (`MusicText`-normalised title with version markers kept, normalised first main artist), not null, backfilled from `title` / `artist_name`; index `(match_title, match_artist)`. These are plan 2's `tracks` columns, created now. |
| `contributors` | Add `lastfm_listeners`, `lastfm_playcount` (nullable). |
| `ResolutionMethod` | Add `LastFm = 'lastfm'`. |
| `popularity_samples` (new) | `id`, `subject_type` (`recording`, `contributor`), `subject_id` (uuid), `source`, `listeners`, `playcount` (nullable), `measured_at`. Index `(subject_type, subject_id, measured_at)`. Appended at every reading, never updated. |
| `similar_recordings` (new, from the parent spec) | `id`, `recording_id` (FK, cascade), `title`, `artist_name`, `match` (0–1), `source`, timestamps. Index `recording_id`. |
| `similar_contributors` (new) | `id`, `contributor_id` (FK, cascade), `name`, `match` (0–1), `source`, timestamps. Index `contributor_id`. |
| `chart_snapshots` (new) | `id`, `source`, `chart` (`global`, `country:BE`, `country:FR`, `country:US`), `taken_on` (date), timestamps. Unique `(source, chart, taken_on)`. |
| `chart_entries` (new) | `id`, `chart_snapshot_id` (FK, cascade), `rank`, `title`, `artist_name`, `listeners` (nullable), `playcount` (nullable), `recording_id` (nullable FK, null on delete). Unique `(chart_snapshot_id, rank)`; index `recording_id`. |

`recording_tags` and `contributor_tags` take Last.fm rows with
`source = lastfm` and Last.fm's own weights. Last.fm tags are never genres by
themselves: `is_genre` stays what MusicBrainz says.

**Recording identity.** A recording is found by `mbid`, then by `isrc` among
rows without an `mbid`, then — new — by `(match_title, match_artist)` among
rows with neither. When a later resolution of the same track finds an
identifier:
- the name-only recording takes it, when no other recording has it;
- otherwise the resolution moves to the identified recording, and the
  name-only one is deleted once no resolution points to it (its tags,
  similar rows and samples cascade; chart entries are nulled and relinked by
  the next snapshot).

### Object architecture

```
app/Services/Metadata/LastFm/
├── LastFmGateway.php              contract: trackInfo, trackTopTags, trackSimilar,
│                                  artistInfo, artistTopTags, artistSimilar,
│                                  topTracks(?country)
├── HttpLastFmGateway.php          key, format, autocorrect; error 6 → null,
│                                  29 and HTTP 429 → MetadataSourceRateLimited,
│                                  11, 16 and 5xx → MetadataSourceUnavailable,
│                                  10 and 26 → a configuration exception
├── RateLimitedLastFmGateway.php   spends CallBudget::LASTFM (4/s)
└── LastFmMapper.php               payloads → WeightedTag, Popularity,
                                   SimilarTrack, SimilarArtist, ChartEntry,
                                   and the corrected title/artist/duration
app/Services/Metadata/Data/        Popularity, SimilarTrack, SimilarArtist, ChartEntry
app/Actions/TakeChartSnapshots.php
app/Jobs/TakeChartSnapshot.php     one chart, enrichment queue
app/Console/Commands/TakeChartSnapshotsCommand.php   metadata:charts
```

Without `services.lastfm.key` (`LASTFM_API_KEY`) the gateway binding is a
null gateway: every Last.fm step is skipped and one warning is logged per run.
`LASTFM_API_SHARED_SECRET` is configured but unused (no user authentication).

### Resolution

`ResolveRecordings` gains a last step, after credits.fm and the MusicBrainz
search, before recording a miss: `track.getInfo` with each reading
(`TrackQuery`). Accepted when:
- the corrected title is the same title (`MusicText::sameTitle`) as the
  reading's, and the corrected artist the same artist as the reading's or as
  the source's artists;
- and, when both durations are known (Last.fm's is not `"0"`), they are within
  5 s.

The recording is found or created by `(match_title, match_artist)` from the
corrected names, with method `lastfm` and confidence 0.5. The `getInfo`
payload is stored as the recording's `lastfm` `info` enrichment, so
description does not ask again.

### Description

`DescribeRecording` and `DescribeContributor` describe with Last.fm whatever
the identifiers, by artist and title (or name). One `EnrichRecording(…,
LastFm)` or `EnrichContributor(…, LastFm)` job makes up to three calls, one
`enrichments` row each, and only for the endpoints that are due (no row, or
`next_attempt_at` passed):

| Subject | Endpoints | Done refreshed after |
|---|---|---|
| Recording | `info` (`track.getInfo`), `top_tags`, `similar` (`limit=50`) | `info` 7 days; others 90 days |
| Contributor | `info` (`artist.getInfo`), `top_tags`, `similar` (`limit=50`) | `info` 7 days; others 90 days |

`EnrichmentStatus::nextAttemptAt()` takes the endpoint's cadence into account
(`not_found` 30 days and `failed` 1 day are unchanged).

### Projection

- `ProjectEnrichment` (recordings) also projects:
  - Last.fm tags into `recording_tags` (top 20, weight ≥ 5);
  - similar tracks into `similar_recordings` (replaced as a whole);
  - listeners and play count onto the recording, plus one
    `popularity_samples` row when the `info` payload is newer than the last
    sample;
  - **a main artist from Last.fm** when MusicBrainz credits none: the
    corrected artist name becomes an `artist` credit with `source = lastfm`,
    matched to a contributor by `normalized_name` like any name-only credit.
- Contributors to describe are the main artists that have no description from
  that source yet: MusicBrainz still requires an MBID, Last.fm does not.
- `ProjectContributorTags` also projects Last.fm artist tags, similar artists,
  listeners and play count, and a sample.

### Charts

`metadata:charts` (scheduled daily, next to `metadata:retry-due`) dispatches
one `TakeChartSnapshot` per chart. A chart already taken today is skipped. The
job asks for the top 200, creates the snapshot and its entries in one
transaction, and links each entry to a recording by
`(match_title, match_artist)`. Four calls a day.

### Rollout

Two gaps would otherwise leave the existing library without Last.fm:
- `metadata:retry-due` only retries rows that exist. It now also dispatches a
  description for every recording, and every main artist, that a source of
  `DescribeRecording::SOURCES` / the contributor sources has never described
  (no `enrichments` row for that subject and source). Any source added later
  fills itself in the same way, within a day.
- Tracks `not_found` wait 30 days. `metadata:enrich --unresolved` queues the
  `not_found` and `pending` tracks for resolution now (`--refresh` keeps its
  meaning: everything, resolved included).

After deploying: set `LASTFM_API_KEY` on Cloud, redeploy, run
`metadata:enrich --unresolved`; the next `metadata:retry-due` (or a manual
run) describes the rest.

### Call volume

- First run: about 1 200 recordings × 3 and about 700 main artists × 3, about
  5 700 calls, about 24 minutes at 4/s.
- Every week: about 1 900 `getInfo` calls, about 8 minutes.
- Resolution: one or two `getInfo` calls per track still unresolved.

### Observability

The existing instruments, with `source=lastfm`: `sonder.enrichment.lookups`,
`sonder.enrichment.lookup.duration`, `sonder.rate_limit.refusals`, and
`sonder.enrichment.resolutions` with `method=lastfm`. New:

| Name | Type | Attributes |
|---|---|---|
| `sonder.enrichment.coverage` | gauge (existing) | new facets `lastfm_tags` (recordings with Last.fm tags of their own or through their main artist), `similar`, `popularity` |
| `sonder.enrichment.chart_entries` | counter | `chart`, `linked` (`true`, `false`) |

## Testing

- `HttpLastFmGateway` against `Http::fake()` with the recorded fixtures: the
  query string (key, `format`, `autocorrect`), error 6 → null, error 29 and
  HTTP 429 → rate limited with `Retry-After`, 11/16/5xx → unavailable,
  10/26 → configuration error.
- `LastFmMapper` on the fixtures: duration in milliseconds and `"0"`, tag
  filter and order, similar tracks and artists, chart ranks.
- Resolution: accepted by Last.fm (confidence 0.5, name-only recording),
  refused on another artist or a duration over 5 s apart, accepted with an
  unknown duration; a later MBID adopted by the name-only recording, and the
  move plus deletion when the MBID belongs to another recording.
- Description: only due endpoints are called; `info` due after 7 days, the
  others after 90.
- Projection: Last.fm tags beside MusicBrainz ones, the Last.fm main artist
  only when MusicBrainz has none, one sample per new reading.
- Charts: idempotent per day, entries linked to known recordings.
- Rollout: `retry-due` describes a recording a source never described, and
  nothing once it is described (a job queued twice is harmless: only due
  endpoints are called); `--unresolved` queues `not_found` and `pending`
  tracks only.
- Without a key: no call, one warning.

## Risks

- **Loose Last.fm matches.** Autocorrect plus a name match may accept a
  homonym. Confidence 0.5 marks these recordings, and the `resolved`
  coverage by method shows how many there are.
- **Fandom and personal tags** (`banana fish`, `seen live`). The mood taxonomy
  decides which tags count; the weight filter only cuts the long tail.
- **Last.fm API stability.** Free and long-lived, but with no SLA; without it
  the data already stored stays usable.
