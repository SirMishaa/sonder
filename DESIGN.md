---
name: Sonder
description: A warm, dark listening room for your YouTube Music library, where discovery is one glance away.
colors:
  amber: "oklch(0.80 0.15 68)"
  amber-light: "oklch(0.84 0.14 75)"
  amber-deep: "oklch(0.66 0.17 48)"
  amber-ink: "oklch(0.22 0.04 60)"
  ink-bg: "oklch(0.165 0.012 60)"
  sleeve: "oklch(0.182 0.013 60)"
  panel: "oklch(0.19 0.013 60)"
  hover: "oklch(0.215 0.014 60)"
  raised: "oklch(0.235 0.014 60)"
  groove-line: "oklch(0.285 0.012 60)"
  paper-text: "oklch(0.94 0.012 80)"
  muted-text: "oklch(0.72 0.018 70)"
  faint-text: "oklch(0.60 0.016 70)"
  signal-green: "oklch(0.78 0.12 150)"
  alarm-red: "oklch(0.66 0.18 28)"
typography:
  wordmark:
    fontFamily: "Bricolage Grotesque, Hanken Grotesk, system-ui, sans-serif"
    fontSize: "25px"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.02em"
    fontVariation: "'opsz' 96"
  display:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "44px"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.04em"
  headline:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "30px"
    fontWeight: 800
    lineHeight: 1.1
    letterSpacing: "-0.03em"
  title:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 700
    lineHeight: 1.4
  body:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "14.5px"
    fontWeight: 400
    lineHeight: 1.5
    fontFeature: "'tnum' 1"
  meta:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "12.5px"
    fontWeight: 500
    lineHeight: 1.4
    fontFeature: "'tnum' 1"
  kicker:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 700
    lineHeight: 1.3
  keycap:
    fontFamily: "Hanken Grotesk, system-ui, sans-serif"
    fontSize: "10.5px"
    fontWeight: 600
    lineHeight: 1.3
rounded:
  xs: "4px"
  sm: "6px"
  md: "9px"
  lg: "12px"
  xl: "16px"
  pill: "999px"
spacing:
  "1": "4px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  "6": "24px"
  "8": "32px"
components:
  button-primary:
    backgroundColor: "{colors.amber}"
    textColor: "{colors.amber-ink}"
    typography: "{typography.title}"
    rounded: "{rounded.md}"
    padding: "8px 14px"
  button-secondary:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.paper-text}"
    typography: "{typography.title}"
    rounded: "{rounded.md}"
    padding: "8px 14px"
  button-secondary-hover:
    backgroundColor: "{colors.raised}"
  button-icon:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.muted-text}"
    rounded: "{rounded.sm}"
    size: "30px"
  button-play:
    backgroundColor: "{colors.amber}"
    textColor: "{colors.amber-ink}"
    rounded: "{rounded.pill}"
    size: "38px"
  nav-item:
    textColor: "{colors.muted-text}"
    typography: "{typography.title}"
    rounded: "{rounded.md}"
    padding: "7px 10px"
  nav-item-active:
    backgroundColor: "{colors.raised}"
    textColor: "{colors.paper-text}"
  search-field:
    backgroundColor: "{colors.ink-bg}"
    textColor: "{colors.faint-text}"
    rounded: "{rounded.md}"
    padding: "7px 10px"
  tag:
    textColor: "{colors.muted-text}"
    typography: "{typography.meta}"
    rounded: "{rounded.pill}"
    padding: "1px 8px"
  track-row:
    textColor: "{colors.paper-text}"
    typography: "{typography.body}"
    padding: "7px 10px"
  track-row-hover:
    backgroundColor: "{colors.hover}"
  keycap:
    backgroundColor: "{colors.ink-bg}"
    textColor: "{colors.faint-text}"
    typography: "{typography.keycap}"
    rounded: "{rounded.sm}"
    padding: "1px 5px"
---

# Design System: Sonder

## 1. Overview

**Creative North Star: "The Listening Room"**

Sonder is a record shop after closing time: warm dark wood, a tube amp glowing in the corner, sleeves everywhere. The interface is that room. Its surfaces are warm near-blacks with a faint film grain, its only light is the amber of the amp, and whatever is playing quietly tints the space around it. It is built to be glanced at from a second screen while working, then explored at length during a discovery session, so it is dense where information helps and calm everywhere else.

Everything answers to one principle from PRODUCT.md: *the music carries the page*. Artwork, titles and artists are the content; the chrome is warm, textured and recessive. Motion and color exist to say one thing, what is playing and what is new, never to decorate.

The system explicitly rejects the generic starter kit ("grey on white, no identity, *Laravel Starter Kit* in the corner"), the Spotify-clone reflex of black and neon green, and anything that reads as a SaaS product built for strangers: onboarding funnels, marketing copy inside the app, hero metrics.

**Key Characteristics:**
- Dark, warm, tinted neutrals (hue 60) with film grain; never flat black.
- One accent, the logo's amber gradient, reserved for action and "now playing".
- A single left sidebar holds the whole navigation and the playlists; it collapses to an icon rail.
- Three surface materials: page, sleeve, glass.
- Skeletons for every load, staggered reveals, 140–520 ms motion on exponential ease-out curves.
- Keyboard first: ⌘K palette, single-key shortcuts shown as keycaps.

## 2. Colors: The Amp-Light Palette

Warm near-black neutrals lit by a single amber voice, with the artwork of the current music allowed to tint its own page.

### Primary
- **Amp Amber** (oklch(0.80 0.15 68)): the one accent. Primary buttons, the play button, progress and volume bars, the now-playing waveform, the active nav icon, "near Rammstein" style hints, the dot on changed playlists. Always drawn as the logo gradient from **Amber Light** (oklch(0.84 0.14 75)) to **Burnt Amber** (oklch(0.66 0.17 48)) when it fills a surface.
- **Amber Ink** (oklch(0.22 0.04 60)): text and icons placed on amber. Never plain black.

### Neutral
- **Ink Background** (oklch(0.165 0.012 60)): the page. Carries a soft warm light from the top-left and a darker pool bottom-right, plus page grain.
- **Sleeve** (oklch(0.182 0.013 60)): the sidebar material, a touch warmer and lighter than the page.
- **Panel** (oklch(0.19 0.013 60)) / **Hover** (oklch(0.215 0.014 60)) / **Raised** (oklch(0.235 0.014 60)): the tonal ladder for containers, hovered rows and selected items.
- **Groove Line** (oklch(0.285 0.012 60)): every 1px divider and border; half-opacity inside tables.
- **Paper Text** (oklch(0.94 0.012 80)): primary text. **Muted Text** (oklch(0.72 0.018 70)): artists, albums, secondary copy. **Faint Text** (oklch(0.60 0.016 70)): metadata, counts, durations, keycaps.

### Status
- **Signal Green** (oklch(0.78 0.12 150)): the "synced" dot only.
- **Alarm Red** (oklch(0.66 0.18 28)): removed playlists, expired cookie, destructive actions.

### Named Rules
**The One Voice Rule.** Amber is the only accent, and it always means action or "now playing". If something amber is neither clickable nor currently playing, it is wrong.

**The Artwork Owns Its Page Rule.** A playlist page may be tinted by its own artwork (blurred, faded into the page). Nowhere else borrows artwork color; the sidebar glow is amber, never the artwork.

**The Never-Pure Rule.** No `#000`, no `#fff`. Every neutral carries chroma 0.012–0.018 at hue 60–80.

## 3. Typography

**UI Font:** Hanken Grotesk (with system-ui, sans-serif)
**Wordmark Font:** Bricolage Grotesque at its display optical size, for the "sonder" wordmark only.

**Character:** one warm, slightly humanist grotesque carries the entire interface, from 44px playlist titles down to 10.5px keycaps. The wordmark alone gets a display cut with ink traps, and its "o" is the ripple mark.

### Hierarchy
- **Display** (800, 44px, 1.0, -0.04em): playlist title in the playlist header.
- **Headline** (800, 30px, 1.1, -0.03em): page titles (Discover, Library).
- **Title** (700, 14px, 1.4): section headings ("Up next", "Would fit this playlist"), button labels, track titles at 600.
- **Body** (400, 14.5px, 1.5, tabular figures): default text, artists, albums.
- **Meta** (500, 12.5px, tabular figures, no letter-spacing): track counts, durations, "Checked 4 min ago", "5 never played".
- **Kicker** (700, 13px, Amp Amber, sentence case): the small label above a title ("Playlist", "Friday evening, 6 new for you").
- **Keycap** (600, 10.5px): shortcut keys, drawn as small raised caps.

### Named Rules
**The No-Mono Rule.** Metadata uses the UI font with tabular figures, never a monospace. Mono and wide letter-spacing were tried and rejected: they fought the other type.

**The No-Tracking Rule.** No positive letter-spacing and no uppercase labels anywhere in the UI. Headlines tighten; nothing widens.

## 4. Elevation

Depth comes from three materials, not from shadows. Shadows exist only to lift floating glass and artwork off the page.

- **Page** (level 1): Ink Background with a warm radial light, and film grain at 5% (`mix-blend-mode: overlay`). Felt, not seen.
- **Sleeve** (level 2): the sidebar. Opaque Sleeve gradient with grain at 9%. Its top section (logo, search, nav) sits under an amber glow radiating from the logo, fading long into the playlist list with no visible edge.
- **Glass** (level 3): the player, the queue panel, the ⌘K palette. Translucent warm gradient (roughly 50–66% opacity), `backdrop-filter: blur(6–16px) saturate(1.3–1.5)`, grain at 18–22%, a 1px inner top highlight (`inset 0 1px 0 oklch(1 0 0 / 0.08)`) and a soft drop shadow.

### Shadow Vocabulary
- **Glass lift** (`0 30px 60px -24px oklch(0 0 0 / 0.85)`): player and palette.
- **Sleeve lift** (`0 34px 60px -28px oklch(0 0 0 / 0.95), 0 0 0 1px oklch(1 0 0 / 0.08)`): the large playlist cover.
- **Card rest** (`0 18px 30px -20px oklch(0 0 0 / 0.8), inset 0 0 0 1px oklch(1 0 0 / 0.06)`): artwork tiles.
- **Amber halo** (`0 0 10px oklch(0.80 0.15 68 / 0.45)`): progress bar fill only.

### Named Rules
**The Grain Budget Rule.** Grain lives on surfaces, never on content. Artwork, text and track rows stay crisp. Page 5%, sleeve 9%, glass 18–22%; nothing else.

**The Glass Is For Floating Rule.** Only elements that float above scrolling content are glass. Cards, rows and sections are never glass.

## 5. Components

### Buttons
- **Shape:** gently rounded (9px); the play button is a circle.
- **Primary:** logo amber gradient (135deg) with Amber Ink text, 8px 14px. Hover brightens slightly, press scales to 0.97.
- **Secondary:** Panel background at 80% with a Groove Line border; hover strengthens the border.
- **Icon:** 30–32px square or circle, Muted Text icon; hover gets Raised background and Paper Text.
- **Keyboard hint:** a keycap inside the button when a shortcut exists ("Refresh R").

### Tags
- **Style:** pill (999px), 1px Groove Line border, Muted Text, Meta type. Used for genre tags on suggestions.

### Track list
- **Structure:** four columns (number, title with 32px artwork and artist, album, time), 1px half-opacity dividers, no zebra.
- **Hover:** Hover background; the number turns into an amber ▶. Nothing else moves.
- **Playing:** title in amber, number replaced by an animated three-bar equalizer, and the track's own waveform animates behind the album column on a faint amber wash.

### Suggestions block
- **Style:** a Panel container at 72% with light backdrop blur, rounded 12px. Each row: artwork, title and artist, then "near **Artist**" in amber plus genre tags, then add (+) and skip (×) icon buttons.
- **Keyboard:** J/K move a selection drawn as a Raised row with a 1px amber inner ring; A adds, X skips. Resolved rows slide out to the right with a short blur; added tracks flash amber once at the bottom of the list.

### Navigation (sidebar)
- **Layout:** wordmark with the ripple mark (40px) → search field (⌘K) → Discover / Library / Stats with icons → "Playlists" header with count → scrollable playlist list → account and sync status at the bottom.
- **Active item:** a pill that glides between items (240ms), amber-tinted gradient with an amber inner ring; the active icon turns amber.
- **Playlist rows:** 34px artwork and title. The playlist that is playing shows its own animated waveform behind the title and a small equalizer; changed playlists show an amber dot; removed playlists are flagged in Alarm Red.
- **Collapsed:** a 68px icon rail (key `[`), labels hidden, artwork only for playlists.

### Player (signature component)
- **Material:** glass, floating 14–16px from the edges, rounded 16px, with a faint amber glow on the artwork side.
- **Content:** artwork, title and artist; previous / play / next with the amber play button; elapsed time, amber gradient progress bar, total time; volume with mute and an amber gradient slider; "Up next" toggle (key Q).
- **Behavior:** track changes swap the artwork and text with a short blur-in. It never covers the open queue panel.

### Logo
- **Mark:** the Ripple, an amber dot inside two broken rings. The rings turn slowly (14s and 22s, opposite directions) only while music plays, and stop when paused.
- **Wordmark:** "sonder" in Bricolage Grotesque 800, the "o" replaced by a closed amber ring with a center dot.

### Loading
- **Skeletons** for every view, mirroring the real layout, with a slow warm shimmer. Content then reveals with a 40ms stagger (translateY 6px, fade), and views enter with a 4px blur-in (520ms).

## 6. Do's and Don'ts

### Do:
- **Do** use the logo amber gradient for every primary action and every "now playing" signal, and nothing else.
- **Do** show freshness and connection health plainly: "Checked 4 min ago", "Changed 3 days ago", the sync dot, the expired-cookie alert.
- **Do** give every load a skeleton that matches the final layout; never a centered spinner in content.
- **Do** keep motion between 140ms and 520ms on exponential ease-out (`cubic-bezier(0.22, 1, 0.36, 1)`), and honor `prefers-reduced-motion` everywhere.
- **Do** show the keyboard shortcut next to any action that has one.
- **Do** let a playlist's artwork tint its own header, blurred and faded, never with a hard edge.

### Don't:
- **Don't** look like the generic starter kit: grey on white, no identity, "Laravel Starter Kit" in the corner.
- **Don't** build anything that reads as a SaaS product built for strangers: onboarding funnels, marketing copy inside the app, hero metrics with big numbers and small labels.
- **Don't** copy Spotify: no pure black with neon green.
- **Don't** use monospace or widely tracked uppercase for metadata.
- **Don't** animate on hover for decoration. The waveform means "playing"; it never appears on a row that is merely hovered.
- **Don't** put grain, blur or glass on artwork, text or track rows.
- **Don't** use gradient text, side-stripe borders, or glassmorphism on anything that does not float.
- **Don't** use `#000` or `#fff`.
