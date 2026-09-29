# Product

## Register

product

## Users

The owner and a handful of friends, each with their own YouTube Music account. They are music listeners first, not administrators of a tool.

Three contexts, in order of frequency:

- **While working.** Sonder sits on a second screen or in a background tab. Visits are quick glances: check what is playing next, start a playlist, confirm a sync finished. Attention belongs to the work, not to Sonder.
- **Discovery sessions.** A deliberate moment spent exploring and sorting new music, with full attention and time to dig into artists, albums and playlists.
- **On mobile.** From the phone, on the move or on the couch, with the same library in hand.

## Product Purpose

Sonder is a self-hosted companion for YouTube Music, built to solve one problem: running out of new music to listen to while working.

It does two jobs, one now and one next:

1. **Show the library better than YouTube Music does.** A clear, pleasant, trustworthy view of every playlist and track, kept in sync without the user thinking about it.
2. **Find music worth hearing.** A discovery engine that surfaces tracks the user has never played and writes them into playlists they actually open.

Success is a user who stops scrolling YouTube Music for something new, because Sonder already put it in a playlist.

## Brand Personality

**Warm, personal, curious.**

- **Warm and personal:** made for oneself and a few friends, not an enterprise product. It speaks plainly and a little informally, like a friend who runs a record shop.
- **Curious:** it invites exploring and digging, with the depth of a music obsessive (credits, albums, connections between artists), never the shallowness of a feed.
- Underneath, the craft of the best tools: fast, dense where density helps, keyboard-friendly, nothing superfluous.

Emotional goal: the quiet pleasure of browsing a well-kept record collection, and the small thrill of finding something new in it.

## Anti-references

- **The generic starter kit.** The current default shadcn look: grey on white, no identity, "Laravel Starter Kit" in the corner. Sonder must stop looking like a template.
- Anything that reads as a SaaS product built for strangers: onboarding funnels, marketing copy inside the app, hero metrics.

References to draw from, for specific reasons:

- **Linear and Raycast:** speed, density, keyboard control, meticulous finish.
- **Last.fm and Rate Your Music:** music culture, rich metadata, the feel of a community of enthusiasts.

## Design Principles

1. **Glanceable first.** Most visits last seconds, from a second screen. The state of the library and the next action must read at a glance, and nothing should ask for attention it does not need.
2. **The music carries the page.** Artwork, titles, artists and albums are the content; interface chrome recedes behind them. Depth is always one step away for the discovery sessions.
3. **Honest about the terrain.** Sonder rides a private API and a pasted cookie that can break at any time. Sync state, freshness and connection health are shown plainly and early, never hidden behind a generic error.
4. **Fast by feel.** Instant feedback, keyboard paths for frequent actions, no waiting without an explanation.
5. **Made for a few people.** Personal voice, no enterprise tone, no features that only make sense for strangers at scale.

## Accessibility & Inclusion

- WCAG 2.2 AA: text and interactive contrast, full keyboard navigation, visible focus states.
- Respect `prefers-reduced-motion` for every animation.
- Layouts must work from phone width up to a large second monitor.
