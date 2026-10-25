# YouTube Extension POC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A WXT browser extension that sends YouTube videos and mixes to the Sonder queue (play now, play next, add to queue) from a Sonder group in YouTube's ⋮ menu, with a floating drawer showing what Sonder knows.

**Architecture:** The extension (content scripts on YouTube and Sonder, a background worker) talks to two Sanctum-protected JSON endpoints. A command is logged, applied to the stored `player_queues` row, and broadcast on a private Reverb channel; the elected Sonder tab replays it through `usePlayer`. A lookup endpoint answers "in the library, which playlists, how many listens" by YouTube video id.

**Tech Stack:** Laravel 13, PHP 8.5, Sanctum, Reverb, Inertia v3 + Vue 3, Pest, Vitest; WXT 0.21 + Vue + Tailwind 4 in a bun workspace; `@grafana/faro-web-sdk` (off unless configured).

**Spec:** `docs/superpowers/specs/2026-10-25-youtube-extension-poc-design.md`

## Global Constraints

- bun/bunx only, never npm/npx.
- PHP: `declare(strict_types=1)`, final classes, PHPStan level 8 on every modified PHP file (`vendor/bin/phpstan analyze --level 8 <files>`), `vendor/bin/pint --dirty --format agent`, tests with `./vendor/bin/pest --tia --parallel <paths>`.
- Actions in `app/Actions` with a single `handle()`; controllers are `final readonly`; Form Requests for validation; Data classes (spatie/laravel-data, `#[TypeScript]`) for payloads shown to JS.
- Arch rule: nothing outside `App\Services\Music\YouTubeMusic` and `App\Providers` may use that namespace. The lookup is a plain DB query on existing `youtube_video_id` columns and lives in `app/Actions`.
- Users have UUID keys: Sanctum's `tokenable` morph must be `uuidMorphs`.
- No polling: the extension calls Sonder only on user actions (scale-to-zero).
- Never log the token or any cookie value.
- Commits: Conventional Commits, no co-author or attribution trailers. Check GPG with `timeout 10`; if locked, `git -c commit.gpgsign=false commit`.
- Copy in the extension UI is French, like the app (`laravel-vue-i18n` strings on the Sonder side go in `lang/`).
- Faro and dashboards are on hold: Faro initialises only when `VITE_FARO_URL` is set; dashboards are JSON files in the repo, never pushed.

## Review Focus

1. **YouTube markup changed or an unknown card type** opened the ⋮ menu: no Sonder group, no console error thrown into the page, one Faro event. Test in Task 8 (`does nothing for an unknown card`).
2. **Token revoked** while the extension still holds it: the API answers 401, the extension clears the token and the drawer shows the signed-out state with a link to the connect page. Tests in Task 6 (`clears the token on 401`) and Task 9 (`shows the signed-out state`).
3. **Stored queue at the 300-track cap** when a command adds tracks: the result keeps the current track and the new tracks, dropping the oldest *past* entries first. Test in Task 3 (`keeps the queue within 300 tracks`).
4. **Mix items without a playable video** (deleted, private, the "mix continues" renderer): skipped; a mix with nothing playable is not sent. Tests in Task 7 (`skips items without a video id`) and Task 3 (validation rejects an empty `items`).
5. **Several Sonder tabs, or none focused yet:** exactly one tab replays the command (the playing one, else the last focused, else the most recently opened). Test in Task 5 (`elects one tab`).

---

### Task 1: Bun workspace and WXT scaffold

**Files:**
- Create: `bunfig.toml`, `extension/` (WXT Vue template: `package.json`, `wxt.config.ts`, `tsconfig.json`, `entrypoints/background.ts`, `entrypoints/popup/*`), `extension/assets/tokens.css`
- Modify: `package.json` (workspaces, catalog, scripts), `.gitignore` (`extension/.output`, `extension/.wxt`), `.github/workflows/*` (new job), lint config used by `vp lint`
- Test: commands below

**Interfaces:**
- Produces: `extension/` package named `sonder-extension`; scripts `dev`, `build`, `zip`, `compile`, `test` (Vitest); `extension/assets/tokens.css` exporting the DESIGN.md colors as CSS variables (`--color-amber`, `--color-amber-light`, `--color-amber-deep`, `--color-amber-ink`, `--color-ink-bg`, `--color-sleeve`, `--color-panel`, `--color-hover`, `--color-raised`, `--color-groove`, `--color-paper`, `--color-muted`, `--color-faint`, `--color-signal-green`, `--color-alarm-red`) copied from `resources/css/app.css` `@theme`.

- [ ] **Step 1:** `bunx wxt@latest init extension -t vue --pm bun`, rename the package to `sonder-extension`, add `"test": "vitest run"` and Vitest, `@vue/test-utils`, `happy-dom` dev deps.
- [ ] **Step 2:** Root `package.json`: `"workspaces": { "packages": ["extension"], "catalog": { "vue", "typescript", "tailwindcss", "@tailwindcss/vite", "vue-tsc" at the root's current versions } }`; replace those versions with `"catalog:"` in both packages. Create `bunfig.toml` with `[install]\nlinker = "hoisted"`. Root scripts: `build` → `vp -C . build`, `build:ssr` → `vp -C . build && vp -C . build --ssr`, `dev` → `vp -C . dev`.
- [ ] **Step 3:** `extension/wxt.config.ts`: Vue module, `vite: () => ({ plugins: [tailwindcss()] })`, `manifest.name = 'Sonder'`, `host_permissions: ['*://*.youtube.com/*', '<SONDER_URL>/*']` where `SONDER_URL` comes from `import.meta.env.WXT_SONDER_URL` (default `https://sonder.mishaa.dev`), permissions `['storage', 'tabs']`.
- [ ] **Step 4:** Keep root lint off the extension: add `extension/**` to the ignore list of the root lint config, and give `extension/` its own `lint` script (`vp lint .` run from `extension/`).
- [ ] **Step 5:** CI job `extension` (paths `extension/**`, `resources/js/types/generated.d.ts`): `bun install`, `cd extension && bun run compile && bun run test && bun run zip`, upload `extension/.output/*.zip`.
- [ ] **Step 6: Verify**

Run: `rm -rf node_modules extension/node_modules && bun install && bun run build && bun run test:types && bun run test:lint && (cd extension && bun run build && bun run compile)`
Expected: all exit 0; `node -e "console.log(require('./extension/node_modules/vite/package.json').version)"` prints 8.x while the root resolves `@voidzero-dev/vite-plus-core`.

- [ ] **Step 7: Commit** `build(extension): scaffold the WXT extension in a bun workspace`

### Task 2: Sanctum and the "Connect the extension" page

**Files:**
- Create: `routes/api.php` (via `php artisan install:api --no-interaction`), migration for `personal_access_tokens` (edit to `uuidMorphs('tokenable')`), `app/Http/Controllers/ExtensionConnectionController.php`, `resources/js/pages/extension-connection/Edit.vue`
- Modify: `app/Models/User.php` (`use HasApiTokens`), `routes/web.php`, `lang/*` for copy, settings navigation
- Test: `tests/Feature/Controllers/ExtensionConnectionControllerTest.php`

**Interfaces:**
- Produces: routes (web, `auth` + `verified`): `GET settings/extension` → `extension-connection.edit`; `POST settings/extension` → `extension-connection.store` (throttle `6,1`), returns JSON `{ token: string }`; `DELETE settings/extension/{tokenId}` → `extension-connection.destroy`. Token name `extension`, abilities `['extension']`.
- Produces: page message `window.postMessage({ type: 'sonder:extension-token', token }, window.location.origin)` after a successful store; the Edit page lists the user's `extension` tokens (id, created_at, last_used_at) with a revoke button.

- [ ] **Step 1: Write failing tests**
  - `it('creates an extension token for the signed-in user')`: POST returns 200, JSON has `token`, `$user->tokens()->where('name', 'extension')->count() === 1`.
  - `it('lists only the user\'s own tokens')`: another user's token is absent from the Inertia props `tokens`.
  - `it('revokes a token')`: DELETE removes it; DELETE of another user's token id is 404.
  - `it('requires a verified session')`: guest gets a redirect to `login`.
- [ ] **Step 2:** Run `./vendor/bin/pest --tia --parallel tests/Feature/Controllers/ExtensionConnectionControllerTest.php` → FAIL (route not defined).
- [ ] **Step 3:** Install Sanctum (`php artisan install:api --no-interaction`), switch the migration to `uuidMorphs`, add `HasApiTokens`, write the controller and page (a primary amber "Connecter l'extension" button, the token list, French copy).
- [ ] **Step 4:** Run the tests → PASS. PHPStan level 8 on the new PHP files, Pint.
- [ ] **Step 5: Commit** `feat(extension): connect the extension with a revocable token`

### Task 3: Player commands on the server

**Files:**
- Create: migration `create_extension_commands_table`, `app/Models/ExtensionCommand.php` + factory, `app/Enums/PlayerCommandAction.php`, `app/Data/PlayerCommandData.php`, `app/Http/Requests/IssuePlayerCommandRequest.php`, `app/Actions/IssuePlayerCommand.php`, `app/Actions/ApplyPlayerCommand.php`, `app/Events/PlayerCommandIssued.php`, `app/Http/Controllers/PlayerCommandController.php`
- Modify: `routes/api.php`, `routes/channels.php`
- Test: `tests/Feature/Controllers/PlayerCommandControllerTest.php`, `tests/Unit/Actions/ApplyPlayerCommandTest.php`, `tests/Feature/PlayerChannelAuthorizationTest.php`

**Interfaces:**
- Produces: `enum PlayerCommandAction: string { case PlayNow = 'play_now'; case PlayNext = 'play_next'; case Enqueue = 'enqueue'; }` (`#[TypeScript]`).
- Produces: `POST /api/extension/commands` (`auth:sanctum`, ability `extension`, `throttle:60,1`). Body: `{ action, kind: 'video'|'mix', listId: string|null, title: string, items: [{ videoId, title, artists, durationSeconds, thumbnailUrl }] }`. `items` 1..50, `videoId` `regex:/^[A-Za-z0-9_-]{11}$/`, `listId` max 64, `title` max 255. Response `202 { id: string }`.
- Produces: `ApplyPlayerCommand::handle(User $user, PlayerCommandData $command): int` (new queue version). Semantics on the stored queue, inside `DB::transaction` with `lockForUpdate` like `SavePlayerQueue`:
  - `play_now` + `mix`: queue = items, index 0, source `{ playlistId: listId, title }`, origin `playlist`.
  - `play_now` + `video`: insert after the current index and make it current (index 0 on an empty queue), origin `queue`.
  - `play_next`: insert right after the current index, `queued: true`.
  - `enqueue`: insert after the run of `queued` entries that follows the current index, `queued: true`.
  - Item keys `ext:{videoId}@{unix ms}`; `album`, `duration`, `playlistId` null; `genres` [].
  - Cap `UpdatePlayerQueueRequest::MAX_TRACKS` (300): drop the oldest entries before the current index first, adjusting the index.
- Produces: `IssuePlayerCommand::handle(User $user, PlayerCommandData $command, ?string $traceparent): ExtensionCommand` — logs the command (`user_id`, `action`, `kind`, `list_id`, `video_ids` json, `created_at`), calls `ApplyPlayerCommand`, dispatches `PlayerCommandIssued`.
- Produces: `PlayerCommandIssued` (`ShouldBroadcastNow`) on `PrivateChannel('player.'.$userId)`, `broadcastAs()` = `player.command`, payload `{ id, action, kind, listId, title, items, traceparent }`. Channel `player.{userId}` authorizes `$user->id === $userId`.
- Produces: the controller sets `sonder.client = extension`, `sonder.extension.action`, `sonder.extension.kind`, `sonder.extension.items` on the active span.

- [ ] **Step 1: Write failing tests**
  - Unit `ApplyPlayerCommandTest`: one test per semantic above, plus `it('keeps the queue within 300 tracks')` (299 past + current + 5 new → 300 entries, current and new kept, index shifted) and `it('bumps the version')`.
  - Feature: `it('rejects a request without a token')` (401), `it('rejects a token without the extension ability')` (403), `it('rejects items without a valid video id')` (422 on `abc` and on an empty `items`), `it('logs, applies and broadcasts a command')` (`Event::fake([PlayerCommandIssued::class])`, assert dispatched with the `traceparent` header value, one `extension_commands` row, queue version incremented).
  - Channel: mirror `YouTubeMusicSyncChannelAuthorizationTest` for `player.{userId}` (owner allowed, other user denied).
- [ ] **Step 2:** Run them → FAIL.
- [ ] **Step 3:** Implement the enum, Data, request, actions, event, controller, routes and channel.
- [ ] **Step 4:** Run → PASS; PHPStan level 8; Pint; `php artisan typescript:transform` (or the project's generate command) so `App.Enums.PlayerCommandAction` exists for JS.
- [ ] **Step 5: Commit** `feat(extension): send play commands to the Sonder queue`

### Task 4: What Sonder knows about a video or a mix

**Files:**
- Create: `app/Actions/DescribeYouTubeVideos.php`, `app/Data/VideoKnowledgeData.php`, `app/Http/Requests/LookupVideosRequest.php`, `app/Http/Controllers/VideoLookupController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Controllers/VideoLookupControllerTest.php`

**Interfaces:**
- Produces: `POST /api/extension/lookup` (`auth:sanctum`, ability `extension`, `throttle:120,1`). Body `{ videoIds: string[] (1..100, same regex as Task 3), listId: string|null }`. Response `{ videos: Record<videoId, VideoKnowledgeData>, list: { sentBefore: bool, lastSentAt: string|null } | null }`.
- Produces: `VideoKnowledgeData { inLibrary: bool, playlists: list<string> (titles, alphabetical, removed playlists excluded), listenCount: int, lastListenedAt: ?string (ISO 8601) }`.
- Produces: `DescribeYouTubeVideos::handle(User $user, list<string> $videoIds, ?string $listId): array{videos: array<string, VideoKnowledgeData>, list: array{sentBefore: bool, lastSentAt: ?string}|null}`. Library = `tracks.youtube_video_id` in playlists of the user's YouTube Music accounts; listens = `listens` of the user; "sent before" = an `extension_commands` row with that `list_id`. At most three queries regardless of the number of ids.

- [ ] **Step 1: Write failing tests**: `it('describes videos in the library with their playlists and listens')`, `it('describes unknown videos as not in the library')`, `it('ignores another user\'s library and listens')`, `it('says whether a mix was sent before')`, `it('runs a bounded number of queries')` (`DB::enableQueryLog()`, 50 ids → ≤ 3 queries besides auth).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement; set `sonder.client = extension` and `sonder.extension.action = lookup` on the active span.
- [ ] **Step 4:** Run → PASS; PHPStan; Pint; regenerate TS types.
- [ ] **Step 5: Commit** `feat(extension): describe what Sonder knows about a video`

### Task 5: The Sonder tab replays commands

**Files:**
- Create: `resources/js/lib/player/tabLeader.ts`, `resources/js/lib/player/playerCommands.ts`, `resources/js/components/shell/PlayerCommandListener.vue`
- Modify: `resources/js/composables/usePlayer.ts` (add `queueFirst`), `resources/js/lib/player/queueSync.ts` (optional `traceparent` header), `resources/js/layouts/AppLayout.vue` (mount the listener)
- Test: `resources/js/lib/player/tabLeader.test.ts`, `resources/js/lib/player/playerCommands.test.ts`, `resources/js/composables/usePlayer.test.ts`

**Interfaces:**
- Consumes: `PlayerCommandIssued` payload (Task 3); `playTracks`, `playNext`, `queueNext` from `usePlayer`.
- Produces: `queueFirst(track: App.Data.TrackData, position: number, source: QueueSource): string` in `usePlayer`: inserts right after the current track with `queued: true` (before other queued entries), plays it when nothing is loaded, returns the key.
- Produces: `createTabLeader(deps?: { storage?: Storage; now?: () => number; id?: string }): { id: string; touch(): void; isLeader(isPlaying: boolean): boolean }`. Each tab writes `{ id, focusedAt }` to `localStorage['sonder:tab-focus']` on focus and on creation; a tab leads if it is playing, or if no tab is playing and its id is the stored one. Playing state is shared through `localStorage['sonder:tab-playing']` (`{ id, at }`, cleared on pause/unload).
- Produces: `applyPlayerCommand(player: Player, command: PlayerCommandPayload): void` in `playerCommands.ts`: items become `TrackData` (`isExplicit: false`, `isAvailable: true`, `album`/`duration` null, `genres` []); `play_now` + `mix` → `playTracks(tracks, 0, { playlistId: listId, title }, 'playlist')`; `play_now` + `video` → `playNext(track, source, 'queue')`; `play_next` → `queueFirst` for each item, in reverse so their order holds; `enqueue` → `queueNext` for each item in order. The next queue save carries the command's `traceparent`.

- [ ] **Step 1: Write failing tests**: `it('elects one tab')` (two leaders created on one fake storage: the playing one leads; with none playing, the last touched leads; exactly one `isLeader` is true), one test per action in `playerCommands.test.ts` asserting the resulting `player.state.queue` keys and `index`, `queueFirst` cases in `usePlayer.test.ts` (nothing loaded → plays; with queued entries → goes first).
- [ ] **Step 2:** Run `bun run test resources/js/lib/player resources/js/composables/usePlayer.test.ts` → FAIL.
- [ ] **Step 3:** Implement; the listener uses `useEcho` on `player.${userId}` / `.player.command` (like `SyncListener.vue`) and calls `applyPlayerCommand` only when `leader.isLeader(player.state.playing)`.
- [ ] **Step 4:** Run → PASS; `bun run test:types`; `bun run lint`.
- [ ] **Step 5: Commit** `feat(player): replay extension commands in one Sonder tab`

### Task 6: Extension core: token, API client, trace context

**Files:**
- Create: `extension/lib/token.ts`, `extension/lib/trace.ts`, `extension/lib/api.ts`, `extension/lib/messages.ts`, `extension/entrypoints/sonder.content.ts`; replace `extension/entrypoints/background.ts`, `extension/entrypoints/popup/App.vue`
- Test: `extension/lib/api.test.ts`, `extension/lib/trace.test.ts`

**Interfaces:**
- Produces: `newTraceparent(random?: (bytes: number) => Uint8Array): string` → `00-<32 hex>-<16 hex>-01`.
- Produces: `createApi(deps: { baseUrl: string; token: TokenStore; fetch?: typeof fetch }): { command(body: CommandBody): Promise<{ id: string }>; lookup(body: LookupBody): Promise<LookupResult> }`; throws `SignedOut` on 401 after `token.clear()`. Headers: `Authorization: Bearer`, `Accept: application/json`, `traceparent`.
- Produces: `TokenStore { get(): Promise<string|null>; set(token: string): Promise<void>; clear(): Promise<void> }` on `storage.local` key `sonderToken`.
- Produces: background message protocol (`messages.ts`): `{ type: 'command', body }`, `{ type: 'lookup', body }`, `{ type: 'token', token }`, `{ type: 'status' }` → `{ signedIn: boolean }`. For `play_now`, background opens `${SONDER_URL}/dashboard` in a new tab when `tabs.query({ url: SONDER_URL + '/*' })` is empty.
- Produces: `sonder.content.ts` on `${SONDER_URL}/settings/extension`: accepts `message` events with `event.origin === location.origin` and `type === 'sonder:extension-token'`, forwards `{ type: 'token' }` to background.

- [ ] **Step 1: Write failing tests**: `it('formats a W3C traceparent')`, `it('sends the bearer token and traceparent')`, `it('clears the token on 401')`, `it('throws SignedOut without a token')`.
- [ ] **Step 2:** Run `cd extension && bun run test` → FAIL.
- [ ] **Step 3:** Implement; the popup shows "Connecté" / "Non connecté" and a link to `settings/extension`.
- [ ] **Step 4:** Run → PASS; `bun run compile`.
- [ ] **Step 5: Commit** `feat(extension): sign in and call Sonder from the background worker`

### Task 7: Reading YouTube cards and mixes

**Files:**
- Create: `extension/lib/youtube/targets.ts`, `extension/lib/youtube/mix.ts`, `extension/lib/youtube/fixtures/next-mix.json`, `extension/lib/youtube/fixtures/cards.html`
- Test: `extension/lib/youtube/targets.test.ts`, `extension/lib/youtube/mix.test.ts`

**Interfaces:**
- Produces: `type Target = { kind: 'video'; videoId: string; title: string; artists: string } | { kind: 'mix'; listId: string; seedVideoId: string | null; title: string }`.
- Produces: `targetFromCard(card: Element): Target | null` for `ytd-rich-item-renderer`, `ytd-video-renderer`, `ytd-compact-video-renderer`, `yt-lockup-view-model` (home, search, watch sidebar): reads the card's `/watch?v=` or `list=` link; a `list=RD…` or `/playlist?list=` link is a mix; anything else returns null.
- Produces: `targetFromWatchPage(doc: Document, url: URL): Target | null`.
- Produces: `parseNextResponse(json: unknown): MixItem[]` (`MixItem = { videoId, title, artists, durationSeconds, thumbnailUrl }`) from `contents.twoColumnWatchNextResults.playlist.playlist.contents[].playlistPanelVideoRenderer`; entries without a valid 11-char `videoId` are skipped.
- Produces: `fetchMix(listId: string, seedVideoId: string | null, doc: Document): Promise<MixItem[]>`: same-origin `POST /youtubei/v1/next?prettyPrint=false` with `{ context: { client: { clientName: 'WEB', clientVersion } }, playlistId, videoId }`; `clientVersion` read from the page's `"INNERTUBE_CLIENT_VERSION":"…"`.

- [ ] **Step 1: Capture fixtures** from the owner's YouTube in Brave: one `next` response for an `RD…` mix (DevTools → Network → `next`), and the outer HTML of a home page with a video card and a mix card. Strip account data (`responseContext`, tracking params, avatars).
- [ ] **Step 2: Write failing tests**: `it('reads a video card')`, `it('reads a mix card')`, `it('returns null for an unknown card')`, `it('parses mix items')` (count and first item from the fixture), `it('skips items without a video id')`.
- [ ] **Step 3:** Run → FAIL.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Run → PASS; `bun run compile`.
- [ ] **Step 6: Commit** `feat(extension): read videos and mixes from YouTube pages`

### Task 8: The Sonder group in YouTube's ⋮ menu

**Files:**
- Create: `extension/entrypoints/youtube.content/index.ts`, `extension/entrypoints/youtube.content/menu.ts`, `extension/entrypoints/youtube.content/SonderMenuGroup.vue`, `extension/lib/youtube/player.ts`
- Test: `extension/entrypoints/youtube.content/menu.test.ts`

**Interfaces:**
- Consumes: `targetFromCard`, `targetFromWatchPage`, `fetchMix` (Task 7); background messages (Task 6).
- Produces: `trackMenuOpener(doc: Document): { lastCard(): Element | null }` (remembers the card whose ⋮ button was clicked, by `click` capture on `button[aria-label]` inside a card); `injectGroup(popup: Element, target: Target, actions: GroupActions): void` (idempotent, one group per popup open).
- Produces: `pauseYouTube(doc: Document): void` (`#movie_player video` `.pause()`).
- Behaviour: the group (shadow root, Sonder tokens) has "Lire maintenant", "Lire ensuite", "Ajouter à la file", "Ouvrir le panneau". Mix actions call `fetchMix` first and send all items. "Lire maintenant" also calls `pauseYouTube`. After a send, the menu closes and a small toast confirms ("Envoyé à Sonder", or "Ouvre Sonder pour l'écouter" when no tab is open for next/queue).

- [ ] **Step 1: Write failing tests** (happy-dom with `cards.html`): `it('adds the group to the menu of a video card')`, `it('adds the group once')`, `it('does nothing for an unknown card')` (no group, no throw, `reportIssue` spy called once).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement; watch for menu popups with a `MutationObserver` on `ytd-popup-container`; re-arm on `yt-navigate-finish`.
- [ ] **Step 4:** Run → PASS; `bun run compile`; manual check: `bun run dev`, load in Brave, open the ⋮ menu of a video and a mix on the home page and on a watch page, send each action, see Sonder react.
- [ ] **Step 5: Commit** `feat(extension): add Sonder actions to YouTube's card menu`

### Task 9: The floating drawer

**Files:**
- Create: `extension/entrypoints/youtube.content/SonderDrawer.vue`, `extension/entrypoints/youtube.content/drawer.ts`
- Test: `extension/entrypoints/youtube.content/SonderDrawer.test.ts`

**Interfaces:**
- Consumes: `lookup` (Task 6), `Target` and `fetchMix` (Task 7); opened by "Ouvrir le panneau" (Task 8).
- Produces: `openDrawer(target: Target): void` / `closeDrawer(): void` (one drawer at a time, `createShadowRootUi`, Escape closes).
- Behaviour (mockup "B, tiroir flottant"): glass panel on the right, header with the Sonder mark and connection dot; video: in library, playlists, listens and last listen; mix: title, "N titres · M dans ta bibliothèque", "déjà envoyé" when `sentBefore`, then one row per track with status "✓ Biblio" / "Jamais joué" (not in library and 0 listens) / "Déjà écouté" (listens but not in library); sticky footer with the three actions. Loading skeleton matching the rows; signed-out state with a link to `settings/extension`; error state with retry. Reduced motion respected.

- [ ] **Step 1: Write failing tests**: `it('shows what Sonder knows about a video')`, `it('lists mix tracks with their status')`, `it('shows the signed-out state')` (lookup rejects with `SignedOut`), `it('shows a skeleton while loading')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement with the DESIGN.md values (glass: translucent warm gradient, `blur(14px) saturate(1.4)`, inner top highlight, glass lift shadow; amber only on actions).
- [ ] **Step 4:** Run → PASS; `bun run compile`; manual check in Brave on a mix and a video.
- [ ] **Step 5: Commit** `feat(extension): show what Sonder knows in a floating drawer`

### Task 10: Observability, ready for the self-hosted Grafana

**Files:**
- Create: `extension/lib/faro.ts`, `extension/grafana/extension-actions.json`
- Modify: `extension/entrypoints/youtube.content/index.ts`, `extension/entrypoints/background.ts`, `extension/package.json` (`@grafana/faro-web-sdk`)
- Test: `extension/lib/faro.test.ts`

**Interfaces:**
- Produces: `initFaro(env: { url?: string; app: string; version: string }): FaroLike | null` (null and no network when `url` is empty); `reportIssue(name: string, attributes: Record<string, string>): void` (no-op without Faro); every user action pushes a Faro event `sonder.action` with `action`, `kind`, `items`, and the `traceparent` sent to Sonder.
- Produces: `extension-actions.json`, a Grafana dashboard with TraceQL panels on the server spans: `{ span.sonder.client = "extension" } | count_over_time() by (span.sonder.extension.action)`, errors by action (`status = error`), p95 duration by action. Data source by name `traces`, editable on import.

- [ ] **Step 1: Write failing tests**: `it('stays off without a collector URL')`, `it('reports an issue when on')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement and wire `reportIssue` into the menu and drawer failure paths.
- [ ] **Step 4:** Run → PASS; `bun run compile`; `bun run build`.
- [ ] **Step 5: Commit** `feat(extension): instrument actions for Faro and Grafana`
