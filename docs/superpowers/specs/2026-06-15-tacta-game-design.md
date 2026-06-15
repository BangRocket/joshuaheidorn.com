# Tacta — Online Multiplayer Game (Design Spec)

**Date:** 2026-06-15
**Status:** Approved for planning
**Author:** Joshua Heidorn (with Claude)

## Summary

A hidden, online, turn-based digital adaptation of **Tacta** (Jason Tremblay / The Op,
2025) for **2–6 players**, added to joshuaheidorn.com alongside the Job Tracker. Players
place double-sided cards to cover opponents' dots while protecting their own; the player
with the most visible dots when every deck is empty wins.

It mirrors the Job Tracker's architecture: an unlisted Slim route group, a JSON API, a
Svelte SPA island, and MySQL-backed state — reskinned to the site's NeoBrutalism look with
dark-mode support. The crucial difference is **no Clerk**: multiplayer guests can't be Clerk
users, so participation is gated by **room code**, leaving the single-admin Clerk setup
untouched.

This spec covers the **base game only**. The five official variants, AI opponents,
spectators, and polish are explicitly deferred (see [Scope](#scope)).

## Goals

- Faithfully reproduce the official Tacta **rules** (deque draw, shape-matching, cover-one-
  card, most-visible-dots-wins) as verified against the published rulebook.
- Real online play for 2–6 players on **PHP shared hosting with no Node runtime and no
  WebSockets** — via short-interval polling.
- Hidden/unlisted, joinable by a shareable **room code / link**, no accounts required.
- Server-authoritative rules so clients cannot cheat or desync.
- Consistent with the existing codebase: thin controllers, SQL only in repositories, plain
  CSS, Phinx migrations, PHPUnit + Vitest, TDD.

## Non-goals (base game)

Comments/chat, accounts/profiles, persistent stats or matchmaking, animations/polish beyond
a clean board, AI opponents, spectators, and the five official variant modes.

## Source of truth for rules

The official rulebook (The Op, © 2025 USAopoly) was retrieved and read in full. Key facts:

- **108 cards = 6 colored decks × 18 cards** (Blue, Green, Orange, Pink, Purple, Red) plus a
  neutral **Starting Card** (white, no dots). 2–6 players, one color each.
- **Shuffle once at start**, then hold the deck face-down and play only the **top or bottom**
  card (a deque); you never look through it.
- Cards are **double-sided; the back is a mirror image of the front.** You may **flip and/or
  rotate** a card before placing it.
- **Center** = the card's total dot count, drawn inside a circle/square/triangle (the
  **suit**, used only by variants).
- **Dots live on edge shapes:** filled shapes carry dots, hollow shapes are blank. Edge
  shapes are **triangles, rectangles, squares.**
- **A move covers exactly ONE matching shape on ONE existing card** — your edge-shape lands
  perfectly over a same-type exposed shape, edges aligned so the underlying outline stays
  continuous. Filled may cover blank and vice-versa (covering hides whatever is underneath).
  If neither outermost card has a legal cover in any orientation, you place a card untouched
  in open space.
- **End:** all decks empty → score = your color's visible dots; most wins; ties share.

## Architecture (mirrors the Job Tracker)

| Concern | Job Tracker | Tacta |
| --- | --- | --- |
| Route group | `/jobs` (Clerk-gated) | **`/tacta`** (unlisted, room-code-gated) |
| JSON API | `/jobs/api/...` | **`/tacta/api/...`** |
| Controller | `JobController` | **`TactaController`** (thin) |
| Repositories | `JobRepository`, `JobSettingsRepository` | **`TactaGameRepository`** (+ helpers as needed) |
| Rules engine | — | **`App\Tacta\*`** support classes (authoritative) |
| Svelte SPA | `islands/jobtracker/` | **`islands/tacta/`** |
| Vite entry | `jobs.js` | **`tacta.js`** (new `rollupOptions.input` entry) |
| Storage | `jobs`, `job_settings` | **`tacta_games`, `tacta_players`, `tacta_moves`** |

- The page routes (`GET /tacta`, `GET /tacta/{code}`) serve the SPA shell via Twig + the
  asset manifest helper, like the jobs page.
- The route group is **unlisted**: not in nav, `noindex`, and excluded from `sitemap.xml`.
- Reskin reuses the site theme tokens; dark mode via the existing theme switcher (as the
  jobtracker reskin already does).

## The rules engine (concrete model)

The engine is implemented in **PHP** (authoritative) under `App\Tacta\`. The Svelte client
reimplements only the lightweight bits needed for an interactive preview of legal
placements; the server is the single source of truth and revalidates every move.

### Cards & deck

- A **card** is a square with **four edge-shapes**, one at each side's midpoint. Each shape
  has a `type ∈ {triangle, square, rectangle}` and is either **hollow** (0 dots) or
  **filled** (1–3 dots). The card's center **value** = the sum of its dots.
- All six colors share the **same 18-card layout set** (fair, matches the physical game);
  color is just a tint. The deck layouts are defined in a data file
  (`app/Tacta/deck.php` or a JSON asset) and validated by tests (value == sum of dots; a
  balanced mix of shapes/dots; total dots per deck recorded).
- **Double-sided:** the "mirror" face is the left-right mirror of the front (swap left/right
  edge-shapes). Choosing the face is part of a move.
- The **Starting Card** has four edge-shapes but **no dots**.

### Board & placement

- The play surface is a square grid; **each card occupies one cell**. A placed card's state
  is `(x, y, rotation ∈ {0,90,180,270}, face ∈ {front,mirror}, z)` — `x,y` are integer cell
  coordinates and **`z` = play order** (later placement = higher = on top).
- Each card carries an **edge-shape on each of its four sides** (N/E/S/W). Rotation permutes
  the side→shape mapping; the **mirror** face swaps the E/W shapes (the back is a left-right
  mirror of the front).
- **Legal move** (validated server-side, "edge-adjacency + tab cover"): place one of your two
  outermost cards into an **empty cell that is orthogonally adjacent to exactly one** placed
  card. The **shared edge's two shapes must be the same type** (square↔square, triangle↔
  triangle, rectangle↔rectangle). Your card, being placed later, lays its edge-tab **over**
  the neighbor's matching tab (covering the dots on the neighbor's tab). Rotation and face may
  be chosen freely to make the shared edge match. The placed card must touch **exactly one**
  existing card — its other three orthogonal neighbors must be empty (this enforces the
  rulebook's "connect to only one card per turn"). The **first** move places adjacent to the
  **Starting Card**.
- **No-legal-move fallback:** if neither outermost card can be legally placed adjacent to any
  single card in any rotation/face, the player places a card in an **isolated empty cell**,
  touching nothing.
- The exact side→offset mapping and rotation/mirror permutation tables are pinned during
  implementation under TDD; the rules above are the contract.

### Visibility & scoring

- Every dot belongs to one of a card's four **edge-shapes (tabs)**; filled tabs carry 1–3
  dots, hollow tabs carry none, and a card's center **value == the sum of its tab dots**.
- A tab's dots are **visible** unless a **later-placed neighbor covers that edge.** Concretely,
  for the shared edge between two adjacent cards, the **lower-`z`** card's tab on that edge is
  covered (dots hidden); the higher-`z` card's tab sits on top (dots visible). A tab facing an
  empty cell is fully visible.
- **Score** = sum of each color's visible tab dots. Game ends when all players have emptied
  their decks; **most visible dots wins; ties share the win.**

## Multiplayer, identity & sync

### Rooms & lobby

- **Create game** (`POST /tacta/api/games`): anyone at `/tacta` can create a room → returns a
  short **room code** and a shareable link `/tacta/{code}`. Creator becomes host.
  *(Open creation is the chosen default; see [Open question 8a](#open-questions).)*
- **Join** (`POST /tacta/api/games/{code}/join`): a guest opens the link, picks a display
  name + an available color, and takes a seat. Lobby shows seated players/colors.
- **Start** (`POST /tacta/api/games/{code}/start`): host starts once ≥2 players are seated.
  The server shuffles each player's deck once, fixes turn order, and sets `status=active`.
  First player = lowest value on either outermost card (tie → lowest combined).
- Rooms **auto-expire** after a period of inactivity (cleanup on access / lightweight sweep).

### Identity

- Each seat is bound to a **signed per-game session cookie** (guest token) — no accounts.
  The token lets a player **reconnect/rejoin** their seat after a reload or disconnect.

### Sync (polling)

- Turn-based ⇒ **short-interval polling** (~1.5s) is sufficient and works on shared hosting
  behind Cloudflare (no WebSockets/SSE).
- `GET /tacta/api/games/{code}/state?since={seq}` returns the current `seq`, status, current
  seat, players + scores, and any **moves since `seq`**. The client applies new moves and
  updates the board.
- `POST /tacta/api/games/{code}/moves` submits an intended placement (which outermost card,
  `x,y,rotation,face`). The server **validates legality + turn ownership**, applies it,
  increments `seq`, advances the turn. Illegal/out-of-turn moves are rejected with a clear
  error; the client rolls back its optimistic placement.

### Data model

- **`tacta_games`** — `id`, `code` (unique), `status` (`lobby|active|done`), `turn_order`
  (seat sequence), `current_seat`, `seq` (monotonic event counter), `settings`,
  `created_at`, `updated_at`.
- **`tacta_players`** — `id`, `game_id`, `seat`, `color`, `display_name`, `guest_token`,
  `deck` (shuffled card order), `is_host`, `joined_at`.
- **`tacta_moves`** — `id`, `game_id`, `seq`, `seat`, `card_id`, `x`, `y`, `rotation`,
  `face`, `z`, `created_at`. The move log is **authoritative**; board state is derived from
  it (optionally cached on the game row for fast polling).

## Client (Svelte island `islands/tacta/`)

A small SPA with three screens, styled in NeoBrutalism + dark mode:

1. **Lobby** — create or join by code/link; pick name + color; seat list; host "Start".
2. **Game** — pan/zoom board; the player's **two playable cards** (top/bottom) with
   flip/rotate controls; **click-to-place** onto highlighted legal targets; turn indicator;
   live per-color score; opponents' remaining-card counts. Polls for state; optimistic local
   placement reconciled on the next poll.
3. **Game over** — final board + final scores, "play again" (new room).

Built as a new Vite entry (`tacta.js`); mounted via the existing island-mounting pattern.

## Testing (TDD throughout)

- **PHPUnit (rules engine):** shape-matching legality across all four rotations and both
  faces; the cover-exactly-one-card constraint; the no-legal-move fallback; first-move-on-
  Starting-Card; z-order dot visibility & scoring; deck integrity (value == sum of dots,
  108-card composition). Repository CRUD + room lifecycle + turn advancement.
- **PHPUnit (API):** create/join/start/move/state happy paths and rejections (out of turn,
  illegal move, joining a full/started game, bad room code).
- **Vitest + jsdom (island):** placement interaction (rotate/flip/preview), lobby flow, and
  polling reconciliation (applying remote moves, rolling back rejected optimistic moves).

## Scope

**Base (this spec):** unlisted online turn-based standard Tacta for 2–6 players with room
codes, server-authoritative rules, polling sync, lobby → game → game-over, full test
coverage.

**Deferred ("add to it later"):** the five official variants — **Limited Space**, **Quick
Round**, **Team Up**, **Free Play** (real-time, no turns), **Sabotage** — plus AI opponents,
spectators, richer card art/animation, and persistent stats.

## Open questions

- **8a — Hidden-ness.** Default: *unlisted + open room creation* (simplest for the owner to
  start games with friends). Stricter alternative: gate **room creation** behind the owner's
  Clerk login while guests still join by link. **Chosen for now: open creation.**
- **8b — Card art.** Default: simple on-brand geometric shapes for the base; fancier art
  deferred. **Chosen for now: simple shapes.**

## Risks & mitigations

- **Geometry ambiguity** (exact placement/coverage math): pin via TDD against the rules
  above; use the approved **edge-adjacency + tab-cover** model (unit grid, shared-edge shape
  match, later card covers the neighbor's tab).
- **No real 108-card data:** deck layouts are designed and validated by tests; fidelity is
  to the *rules*, not the exact printed cards.
- **Polling load:** lightweight `since={seq}` responses; cache derived board state on the
  game row; auto-expire idle rooms.
- **Cheating/desync:** server is authoritative; clients never compute final state.
