# YouTube browser extension (POC)

## Intent

The owner listens to music mostly on YouTube (84% of plays in the Takeout
export, 2 months, about 7,400 plays), often through YouTube's suggested mixes
(`RD…` lists) with the music video in theater mode on a second screen. None of
that reaches Sonder: mixes cannot be read through any API, and adding a track
or a mix to Sonder by hand is tedious enough that it never happens.

The extension is the bridge. From any YouTube page, a video or a mix goes to
Sonder in one or two clicks, and a panel shows what Sonder already knows about
it. It is a proof of concept for the extension as the hub of the YouTube
integration; later bricks (scrobbling, cookie push, raw audio) plug into the
same extension.

## Goals

- From a YouTube card (home, search, sidebar suggestions) or the watch page,
  send a video or a mix to Sonder: **play now**, **play next**, or **add to
  queue**.
- A Sonder drawer on YouTube that shows, for a video or a mix, what Sonder
  knows (in the library, which playlists, listen count, for a mix: track by
  track, known or new) and offers the same three actions.
- The extension signs in to Sonder with a revocable token.
- Every extension action is observable end to end, from the click to the
  Sonder tab that plays it.

## Non-goals

- Saving a mix as a playlist. It needs Sonder-owned playlists, which arrive
  with the multi-provider foundation.
- Scrobbling YouTube plays. Next brick, right after the POC.
- Pushing the YouTube Music cookie. The dedicated private-window session has
  been alive for more than 28 hours (main-profile cookies died in under 4), so
  pushing the main profile's cookie could recreate the concurrent use that
  seems to kill sessions. Revisit only if the dedicated session dies.
- Raw audio, AutoMix, crossfade. Parked.
- Store publishing (Chrome Web Store, AMO). The owner loads the unpacked build
  in Brave.
- Mobile.

## Anti-goals

- Anything that makes the YouTube home noisier. The owner chose the ⋮ menu
  over buttons or badges on thumbnails.
- Shipping YouTube-specific code outside `app/Services/Music/YouTubeMusic`
  on the server, against the multi-provider rule.
- A second queue model. The extension drives the existing Sonder queue.

## Decisions made with the owner

| Topic | Decision |
| --- | --- |
| Card actions | A **Sonder group inside YouTube's ⋮ menu** of every card: Play now, Play next, Add to queue, Open panel (mockup "B, menu ⋮"). Nothing drawn on thumbnails. |
| Panel | A **floating glass drawer** over the right side of the page, opened from the menu, usable on every YouTube page (mockup "B, tiroir flottant"). For a mix it lists the tracks with their status (in library, new, never played) and keeps the actions in a sticky footer. |
| Look | Sonder's design system (DESIGN.md, "The Listening Room"): amber accent, Hanken Grotesk, warm neutrals. Inside YouTube's own menu, the Sonder group sits on a warm tinted block so it reads as Sonder without restyling YouTube. |
| Framework | **WXT** with Vue and Tailwind 4, reusing Sonder's tokens. |
| Repository | Same repo, in `extension/`, as a **bun workspace** (spike done, see below). |
| Auth | A token, not the session cookie: **Laravel Sanctum** personal access tokens (dependency approved). Cleaner than CSRF plus session expiry. |
| Observability | `traceparent` on every API call, so extension actions join the server trace; extension errors and actions instrumented for **Grafana Faro**; a Grafana panel for extension actions. Faro and the panels are **on hold**: the owner is moving Grafana to a self-hosted instance at a friend's. |
| POC scope | Play now / next / queue, the drawer, auth, observability. No playlist saving, no scrobbling, no cookie push. |

## Workspace setup (verified by a spike)

- Root `package.json`: `"workspaces": { "packages": ["extension"], "catalog":
  { … } }`. The catalog pins the shared versions: `vue`, `typescript`,
  `tailwindcss`, `@tailwindcss/vite`, `vue-tsc`. Both packages reference them
  as `"catalog:"`.
- `bunfig.toml` with `[install] linker = "hoisted"`. The default isolated
  linker broke the root `tsc` (a type error on the `laravel-vue-i18n` plugin
  in `resources/js/app.ts`); hoisted fixes it.
- Root scripts become `vp -C . build` / `vp -C . dev` (and the SSR variant):
  vite-plus refuses to build at a workspace root without a target.
- Root `vp lint` and `vp fmt` must either exclude `extension/` or give it its
  own config: type-aware lint failed to resolve `.vue` modules there.
- WXT gets the real Vite (8.x) nested under `extension/`, while the root
  keeps its `vite` alias to vite-plus. No conflict.
- Laravel Cloud's build can install only the root with
  `bun install --filter './'` (310 packages instead of 654) if build time
  matters.
- CI: a separate job for `extension/` (type check, Vitest, `wxt build`, zip
  kept as an artifact), triggered when `extension/**` or the generated types
  change.

## How it works

### Reading YouTube

A content script on `*.youtube.com`:

- Adds the Sonder group to the ⋮ menu of video and mix cards, and to the
  watch page's own menu. It finds the card's video id or list id from the
  card's link (`/watch?v=…`, `&list=RD…`, `/playlist?list=…`).
- Reads a mix's tracks from YouTube's own data, with the owner's YouTube
  session: `ytInitialData` on a watch page that plays the mix, or a call to
  `/youtubei/v1/next` with the list id from any other page. A mix is endless;
  the POC takes the first page YouTube returns (about 25 to 50 items).
- Keeps up with YouTube's single-page navigation (`yt-navigate-finish`).

### Sending to Sonder

Queue items are videos: `videoId`, title, artist (channel name or the
artist YouTube gives), duration, thumbnail. The existing queue already holds
that shape and the IFrame player plays any video id, so playing needs no
matching against the library.

The server receives a command (`play_now`, `play_next`, `enqueue`) with one or
more items and the origin (`video` or `mix`, with the list id):

- **A Sonder tab is open.** The command is broadcast on a private Reverb
  channel for the user; the tab runs it through the existing `usePlayer`
  functions (`playTracks`, `playNext`, queueing) and saves the queue as it does
  today. Presence on that channel tells the server whether a tab is there.
  When several tabs are open, only one runs it (the one that is playing,
  otherwise the most recently focused).
- **No Sonder tab.** The server applies the command to the stored queue
  (bumping its version, so the next tab takes it on load), and the extension
  offers to open Sonder. For "play now" it opens Sonder directly.
- "Play now" pauses the YouTube video in the current tab.

### The drawer

Opened from "Open panel" in the menu. It asks Sonder what it knows about a
video or a list:

- Video: in the library or not, the playlists it belongs to, listen count and
  last listen.
- Mix: the same per track, plus how many are already known, and whether the
  mix was sent to Sonder before.
- The "played often on YouTube but in no playlist" hint is out of the POC (it
  needs YouTube plays, which come with scrobbling). Leave room for one line.

Lookups are by YouTube video id; this endpoint is YouTube-specific and lives
with the YouTube Music service code.

### Signing in

A Sonder settings page "Connect the extension": the owner approves, the page
hands a token to the extension through `externally_connectable` messaging,
and the token can be revoked from the same page. The extension stores it in
extension storage and sends it as a bearer token. Reverb private channel
authorization accepts the same token.

### Observability

- The extension creates a span per user action and sends `traceparent` with
  every API call, so the server spans and the Reverb broadcast hang off the
  same trace. Attributes: `sonder.client = extension`, the action, the origin
  (`video` or `mix`), the number of items, and whether a Sonder tab handled it.
- The Sonder tab that runs a broadcast command records it (span or log linked
  to the trace id carried in the event).
- Extension errors go to Grafana Faro.
- A dashboard panel: extension actions by type and outcome, from traces.

## Constraints

- Laravel 13, PHP 8.5, Octane (FrankenPHP), Inertia v3 with Vue, Pest, PHPStan
  level 8, Pint, bun only (never npm).
- Multi-provider rule: YouTube-specific server code stays under
  `app/Services/Music/YouTubeMusic`; the command and queue endpoints are
  provider-neutral.
- Octane: no request state in singletons.
- Scale-to-zero on Laravel Cloud: nothing in the extension may poll Sonder on
  a timer. Calls happen on user actions only.
- Never log the token or any cookie value.

## On hold: Grafana

Grafana Cloud's free stack is paused (quota exhausted) and the owner is moving
to a self-hosted Grafana. Until it is up:

- Build the instrumentation anyway: spans, `traceparent`, span attributes, and
  the Faro integration behind configuration (collector URL and app key from
  the extension's build-time env). With no URL configured, Faro stays off and
  nothing breaks.
- Define the extension panel (actions by type and outcome, Sonder tab handled
  or not, errors) as dashboard JSON in the repo, ready to import on the new
  host. Do not push dashboards anywhere.
- The suspected cause of the exhausted quota (a random `service.instance.id`
  per process since 0015ec3, which multiplies metric series) matters on the
  self-hosted Prometheus too. It is tracked separately, not in this POC.

## Calls made by Claude (check these)

- **Keyboard shortcuts:** none in the POC. The mockup showed ⇧P and ⇧N, but
  those are YouTube's own previous and next shortcuts.
- **Watch page:** the Sonder group is added to the watch page's ⋮ menu too, so
  the drawer opens the same way everywhere.
- **Several Sonder tabs:** the playing tab wins, otherwise the most recently
  focused.
- **No tab open:** the command lands in the stored queue; "play now" opens
  Sonder.
- **Mix size:** first page from YouTube only.
- **Pausing YouTube** on "play now".
- **"Sent before":** the server keeps a small log of extension commands (list
  or video id, action, time), which also answers "mix already sent" in the
  drawer.
- **Listens:** tracks played from an extension command are recorded with the
  existing origins (`queue` for queued items; a mix played now behaves like a
  playlist, with the mix title as its source). No new origin until scrobbling
  needs one.

## Left to the builder

- How the Sonder group is attached to YouTube's menu (it is rendered
  lazily), and how to survive YouTube markup changes (fail quietly, record a
  Faro event).
- The exact API routes and payloads, within the rules above.
- Drawer details beyond the mockup: loading skeletons, empty and error
  states, the signed-out state.
- Whether the content script renders the drawer in a shadow root
  (`createShadowRootUi`) or an iframe.
