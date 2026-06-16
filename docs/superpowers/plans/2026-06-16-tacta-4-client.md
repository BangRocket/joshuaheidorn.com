# Tacta Phase 4 — Svelte Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the playable Tacta client — a Svelte 5 island (`islands/tacta/`) with a lobby → game → game-over flow that polls the JSON API, renders the board and the player's hand, highlights legal placements, and submits moves.

**Architecture:** The server stays authoritative. Two small API additions feed the client: a static `GET /tacta/api/deck` (the 18 card layouts, for rendering) and a `you.legal` list of legal placements added to the `state` response when it's your turn. The client therefore needs **no game engine** — it renders cards from the deck data, highlights the server's legal cells, and submits the chosen placement. State lives in a Svelte 5 `$state` store with a polling loop; UI is small components reskinned to the site's NeoBrutalism look (reusing the `--color-*` theme tokens, so dark mode rides the site theme switcher).

**Tech Stack:** Svelte 5 (runes), Vite (new `tacta` entry → `public/assets/`), Vitest + jsdom for island tests; PHP/Slim for the two API additions. Build with `yarn build`; JS tests `yarn test`; PHP tests `vendor/bin/phpunit` (MySQL up).

---

## Spec coverage & prerequisites

Implements the spec's **Client (Svelte island)** section and finishes the feature. Builds on
Phases 1–3 (engine, data, API) on branch `tacta-game`. The page shell `app/views/tacta.twig`
(created in Phase 3) already references `islands/tacta.js` behind an `{% if assets.js %}`
guard, so once this phase builds the bundle the page lights up automatically.

**Prerequisites:** `docker compose up -d` (MySQL, for PHP tests); `yarn install` already done
(deps unchanged). The dev loop: `yarn build` then `php -S 127.0.0.1:8088 -t public public/router.php`.

## Key client design

- **Rendering data:** `GET /tacta/api/deck` returns the 18 layouts as
  `{index, edges:{N,E,S,W:{shape,dots}}, value, suit}`. The client fetches it once. A card id
  `"{color}-{n}"` → layout `n-1`; `edgeAt(layout, worldSide, rotation, mirror)` (a 4-line JS
  transform mirroring the PHP one) gives the shape shown on a world side for drawing.
- **Legal moves:** when `you.your_turn` and the game is active, `state` includes
  `you.legal = [{card_id, draw_end, x, y, rotation, mirror}, …]` (the server enumerates
  `Rules::legalConnects` for both outermost cards). The client highlights those cells; clicking
  one submits that exact placement. No client-side legality logic.
- **Board accumulation:** the store keeps a `cells` map keyed by `"x,y"`; each poll appends the
  returned incremental `moves` (filtered by `since = seq`). Scores/`current_seat`/`status` come
  straight from the response.
- **Screens:** `lobby` (create or join, seat list, host Start) → `game` (board + hand +
  scoreboard + turn banner) → `over` (final scores + winner). The store's `screen` is derived
  from `status` plus whether the player has joined.

## File structure

```
app/Controllers/TactaController.php   # + deck() and you.legal in state()   (modify)
app/routes.php                        # + GET /tacta/api/deck               (modify)
tests/Http/TactaApiTest.php           # + deck + legal-moves tests          (modify)
vite.config.js                        # + tacta entry                       (modify)
islands/tacta.js                      # island entry (mounts "Tacta")
islands/tacta/lib/api.js              # fetch wrapper for /tacta/api
islands/tacta/lib/cards.js            # deck cache + edgeAt transform + helpers
islands/tacta/lib/state.svelte.js     # $state store + polling + actions
islands/tacta/App.svelte              # screen router
islands/tacta/components/Lobby.svelte
islands/tacta/components/Game.svelte
islands/tacta/components/Board.svelte
islands/tacta/components/Card.svelte
islands/tacta/components/GameOver.svelte
islands/tacta/styles/global.css       # reskin (aliases site --color-* tokens)
islands/tacta/lib/cards.test.js
islands/tacta/lib/state.test.js
islands/tacta.test.js                 # mount smoke test
```

---

## Task 1: Server — deck endpoint + legal moves in `state`

**Files:**
- Modify: `app/Controllers/TactaController.php`, `app/routes.php`
- Modify: `tests/Http/TactaApiTest.php`

- [ ] **Step 1: Add the failing tests**

Add to `tests/Http/TactaApiTest.php`:
```php
    public function test_deck_endpoint_returns_18_layouts(): void
    {
        $app = $this->app();
        $res = $this->get($app, '/tacta/api/deck');
        $this->assertSame(200, $res->getStatusCode());
        $body = $this->body($res);
        $this->assertCount(18, $body['layouts']);
        $first = $body['layouts'][0];
        $this->assertArrayHasKey('edges', $first);
        $this->assertArrayHasKey('N', $first['edges']);
        $this->assertArrayHasKey('shape', $first['edges']['N']);
        $this->assertArrayHasKey('dots', $first['edges']['N']);
    }

    public function test_state_includes_legal_moves_for_the_current_player(): void
    {
        $app = $this->app();
        [$code, $hostToken, $guestToken] = $this->twoPlayerLobby($app);
        $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);

        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $hostToken]));
        $currentToken = $state['current_seat'] === 0 ? $hostToken : $guestToken;

        $me = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $currentToken]))['you'];
        $this->assertTrue($me['your_turn']);
        $this->assertNotEmpty($me['legal']); // a first move against the starting card always exists
        $first = $me['legal'][0];
        foreach (['card_id', 'draw_end', 'x', 'y', 'rotation', 'mirror'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        // The non-current player gets no legal list.
        $otherToken = $currentToken === $hostToken ? $guestToken : $hostToken;
        $other = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $otherToken]))['you'];
        $this->assertFalse($other['your_turn']);
        $this->assertArrayNotHasKey('legal', $other);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaApiTest`
Expected: FAIL — `/tacta/api/deck` 404 and `you.legal` missing.

- [ ] **Step 3a: Add the route**

In `app/routes.php`, inside the `/tacta` group, add (next to the other GET route):
```php
        $group->get('/api/deck', [$tactaCtrl, 'deck']);
```

- [ ] **Step 3b: Add the controller code**

In `app/Controllers/TactaController.php`, add `use App\Tacta\Deck;`, `use App\Tacta\Rules;`,
and `use App\Tacta\Side;` to the imports. Add the `deck` action (after `page`):
```php
    public function deck(Request $request, Response $response): Response
    {
        $layouts = [];
        foreach (Deck::forColor('blue') as $i => $card) {
            $edges = [];
            foreach ([Side::N, Side::E, Side::S, Side::W] as $side) {
                $edges[$side->name] = [
                    'shape' => $card->edge($side)->shape->value,
                    'dots' => $card->edge($side)->dots,
                ];
            }
            $layouts[] = [
                'index' => $i,
                'edges' => $edges,
                'value' => $card->value(),
                'suit' => $card->suit->value,
            ];
        }

        return $this->json($response, ['layouts' => $layouts]);
    }
```

In `state()`, after the `you` block is built (where `hand` is set), add the legal list. Replace:
```php
            if ($game['status'] === 'active' && is_array($me['deck'])) {
                $you['hand'] = $this->hand($me, $counts[$me['seat']] ?? ['top' => 0, 'bottom' => 0]);
            }
```
with:
```php
            if ($game['status'] === 'active' && is_array($me['deck'])) {
                $seatCounts = $counts[$me['seat']] ?? ['top' => 0, 'bottom' => 0];
                $you['hand'] = $this->hand($me, $seatCounts);
                if ($you['your_turn']) {
                    $you['legal'] = $this->legalMoves($board, $me, $seatCounts);
                }
            }
```

Add the `legalMoves` helper (near `hand`):
```php
    /**
     * Legal connecting placements for the current player's two outermost cards.
     *
     * @param array<string,mixed> $me
     * @param array{top:int,bottom:int} $counts
     * @return list<array{card_id:string,draw_end:string,x:int,y:int,rotation:int,mirror:bool}>
     */
    private function legalMoves(\App\Tacta\Board $board, array $me, array $counts): array
    {
        $deck = $me['deck'];
        $head = $counts['top'];
        $tail = count($deck) - 1 - $counts['bottom'];
        if ($head > $tail) {
            return [];
        }
        $ends = $head === $tail
            ? [['top', $deck[$head]]]
            : [['top', $deck[$head]], ['bottom', $deck[$tail]]];

        $cards = Deck::forColor($me['color']);
        $out = [];
        foreach ($ends as [$drawEnd, $index]) {
            foreach (Rules::legalConnects($board, $cards[$index], $me['color'], $board->nextZ()) as $placement) {
                $out[] = [
                    'card_id' => $me['color'] . '-' . ($index + 1),
                    'draw_end' => $drawEnd,
                    'x' => $placement->x,
                    'y' => $placement->y,
                    'rotation' => $placement->rotation,
                    'mirror' => $placement->mirror,
                ];
            }
        }

        return $out;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaApiTest`
Expected: PASS (all TactaApiTest tests, now including the 2 new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/TactaController.php app/routes.php tests/Http/TactaApiTest.php
git commit -m "feat(tacta): deck endpoint + legal-move list in state for the client

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Vite entry, island bootstrap, API client, card helpers

**Files:**
- Modify: `vite.config.js`
- Create: `islands/tacta.js`, `islands/tacta/lib/api.js`, `islands/tacta/lib/cards.js`
- Test: `islands/tacta/lib/cards.test.js`

- [ ] **Step 1: Write the failing test**

Create `islands/tacta/lib/cards.test.js`:
```js
import { describe, it, expect } from "vitest";
import { edgeAt, parseCardId } from "./cards.js";

// A layout with distinct shapes per side (N/E/S/W).
const layout = {
  edges: {
    N: { shape: "triangle", dots: 1 },
    E: { shape: "square", dots: 0 },
    S: { shape: "rectangle", dots: 2 },
    W: { shape: "square", dots: 1 },
  },
};

describe("parseCardId", () => {
  it("splits color and 1-based index", () => {
    expect(parseCardId("red-7")).toEqual({ color: "red", index: 6 });
  });
});

describe("edgeAt", () => {
  it("identity at rotation 0, no mirror", () => {
    expect(edgeAt(layout, "N", 0, false).shape).toBe("triangle");
    expect(edgeAt(layout, "E", 0, false).shape).toBe("square");
  });

  it("clockwise rotation moves the north edge onto the east face", () => {
    expect(edgeAt(layout, "E", 1, false).shape).toBe("triangle");
  });

  it("mirror swaps east and west", () => {
    expect(edgeAt(layout, "E", 0, true).shape).toBe("square"); // canonical W
    expect(edgeAt(layout, "E", 0, true).dots).toBe(1);
    expect(edgeAt(layout, "N", 0, true).shape).toBe("triangle"); // N/S unaffected
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `yarn test islands/tacta/lib/cards.test.js`
Expected: FAIL — cannot resolve `./cards.js`.

- [ ] **Step 3a: Add the Vite entry**

In `vite.config.js`, add a `tacta` entry to `rollupOptions.input` (alongside `main`, `admin`,
`jobs`):
```js
            input: {
                main: "islands/main.js",
                admin: "islands/admin.js",
                jobs: "islands/jobs.js",
                tacta: "islands/tacta.js",
            },
```

- [ ] **Step 3b: Create the island entry**

Create `islands/tacta.js`:
```js
import { mountIslands } from "./mount-islands.js";
import "./tacta/styles/global.css";
import Tacta from "./tacta/App.svelte";

mountIslands({ Tacta });
```

- [ ] **Step 3c: Create the API client**

Create `islands/tacta/lib/api.js`:
```js
// Thin fetch wrapper around the Tacta JSON API. Returns parsed JSON or throws.
const API = "/tacta/api";

async function request(method, url, body) {
  const opts = { method, headers: {} };
  if (body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(url, opts);
  const text = await res.text();
  const data = text ? JSON.parse(text) : null;
  if (!res.ok) {
    throw new Error(data?.error || `Request failed (${res.status})`);
  }
  return data;
}

export const api = {
  deck: () => request("GET", `${API}/deck`),
  createGame: () => request("POST", `${API}/games`, {}),
  join: (code, name, color) => request("POST", `${API}/games/${code}/join`, { name, color }),
  start: (code) => request("POST", `${API}/games/${code}/start`, {}),
  move: (code, move) => request("POST", `${API}/games/${code}/moves`, move),
  state: (code, since) => request("GET", `${API}/games/${code}/state?since=${since}`),
};
```

- [ ] **Step 3d: Create the card helpers**

Create `islands/tacta/lib/cards.js`:
```js
// Pure helpers for rendering cards from the server's deck layouts. No game logic.
export const SIDES = ["N", "E", "S", "W"];
const INDEX = { N: 0, E: 1, S: 2, W: 3 };
const FROM_INDEX = ["N", "E", "S", "W"];

export const COLORS = ["blue", "green", "orange", "pink", "purple", "red"];

export function parseCardId(cardId) {
  const [color, n] = cardId.split("-");
  return { color, index: Number(n) - 1 };
}

/**
 * The edge shown on a world-facing side after mirror (swap E/W) + clockwise
 * rotation — mirrors the PHP PlacedCard::edgeAt transform, for rendering only.
 */
export function edgeAt(layout, worldSide, rotation, mirror) {
  let i = (INDEX[worldSide] - rotation + 4) % 4;
  if (mirror) {
    if (i === 1) i = 3;
    else if (i === 3) i = 1;
  }
  return layout.edges[FROM_INDEX[i]];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `yarn test islands/tacta/lib/cards.test.js`
Expected: PASS (3 describe blocks, 5 assertions).

- [ ] **Step 5: Commit**

```bash
git add vite.config.js islands/tacta.js islands/tacta/lib/api.js islands/tacta/lib/cards.js islands/tacta/lib/cards.test.js
git commit -m "feat(tacta): vite entry, island bootstrap, api client, card helpers

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: State store + polling

**Files:**
- Create: `islands/tacta/lib/state.svelte.js`
- Test: `islands/tacta/lib/state.test.js`

The store holds the whole client state. `applyState()` merges a poll response (appending new
moves into the `cells` map). `poll()` runs on an interval; actions wrap the API.

- [ ] **Step 1: Write the failing test**

Create `islands/tacta/lib/state.test.js`:
```js
import { describe, it, expect, beforeEach, vi, afterEach } from "vitest";
import { store, applyState, resetForTest } from "./state.svelte.js";

describe("applyState", () => {
  beforeEach(() => resetForTest());

  it("appends incremental moves into the cells map and tracks seq", () => {
    applyState({
      status: "active", seq: 3, current_seat: 1, scores: { red: 2 },
      players: [{ seat: 0, color: "red" }], you: { seat: 0, color: "red", your_turn: false },
      moves: [{ seq: 2, card_id: "red-1", x: 0, y: -1, rotation: 0, mirror: false, z: 1 }],
    });
    expect(store.seq).toBe(3);
    expect(store.status).toBe("active");
    expect(store.cells["0,-1"].card_id).toBe("red-1");

    // A later poll only carries newer moves; existing cells remain.
    applyState({
      status: "active", seq: 4, current_seat: 0, scores: { red: 2, blue: 1 },
      players: [{ seat: 0, color: "red" }], you: { seat: 0, color: "red", your_turn: true },
      moves: [{ seq: 4, card_id: "blue-2", x: 1, y: 0, rotation: 0, mirror: false, z: 2 }],
    });
    expect(store.seq).toBe(4);
    expect(store.cells["0,-1"].card_id).toBe("red-1"); // kept
    expect(store.cells["1,0"].card_id).toBe("blue-2"); // added
    expect(store.you.your_turn).toBe(true);
  });

  it("derives the screen from status + membership", () => {
    resetForTest();
    expect(store.screen).toBe("lobby");
    applyState({ status: "active", seq: 1, current_seat: 0, scores: {}, players: [], moves: [], you: { seat: 0 } });
    expect(store.screen).toBe("game");
    applyState({ status: "done", seq: 9, current_seat: null, scores: {}, players: [], moves: [], you: { seat: 0 } });
    expect(store.screen).toBe("over");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `yarn test islands/tacta/lib/state.test.js`
Expected: FAIL — cannot resolve `./state.svelte.js`.

- [ ] **Step 3: Write the implementation**

Create `islands/tacta/lib/state.svelte.js`:
```js
import { api } from "./api.js";

export const store = $state({
  screen: "lobby",      // lobby | game | over
  code: null,
  joined: false,
  deck: null,           // layouts array, fetched once
  status: "lobby",
  seq: -1,
  currentSeat: null,
  players: [],
  scores: {},
  cells: {},            // "x,y" -> move
  you: null,            // { seat, color, your_turn, hand, legal }
  selected: "top",      // which hand card is selected: "top" | "bottom"
  error: null,
  polling: false,
});

let timer = null;

function deriveScreen() {
  if (store.status === "done") return "over";
  if (store.status === "active") return "game";
  return "lobby";
}

/** Merge a /state response into the store (appending new moves). */
export function applyState(resp) {
  store.status = resp.status;
  store.seq = resp.seq;
  store.currentSeat = resp.current_seat;
  store.players = resp.players ?? [];
  store.scores = resp.scores ?? {};
  store.you = resp.you ?? null;
  for (const m of resp.moves ?? []) {
    store.cells[`${m.x},${m.y}`] = m;
  }
  store.screen = deriveScreen();
}

export async function loadDeck() {
  if (store.deck) return;
  store.deck = (await api.deck()).layouts;
}

export async function createGame() {
  store.error = null;
  const { code } = await api.createGame();
  store.code = code;
  return code;
}

export async function joinGame(code, name, color) {
  store.error = null;
  try {
    await api.join(code, name, color);
    store.code = code;
    store.joined = true;
    await loadDeck();
    await pollOnce();
    startPolling();
  } catch (e) {
    store.error = e.message;
  }
}

export async function startGame() {
  store.error = null;
  try {
    await api.start(store.code);
    await pollOnce();
  } catch (e) {
    store.error = e.message;
  }
}

export async function submitMove(legal) {
  store.error = null;
  try {
    await api.move(store.code, {
      draw_end: legal.draw_end,
      x: legal.x, y: legal.y, rotation: legal.rotation, mirror: legal.mirror,
    });
    await pollOnce();
  } catch (e) {
    store.error = e.message;
  }
}

export function selectCard(which) {
  store.selected = which;
}

export async function pollOnce() {
  try {
    applyState(await api.state(store.code, store.seq));
  } catch (e) {
    store.error = e.message;
  }
}

export function startPolling() {
  if (store.polling) return;
  store.polling = true;
  timer = setInterval(pollOnce, 1500);
}

export function stopPolling() {
  store.polling = false;
  if (timer) clearInterval(timer);
  timer = null;
}

/** Test-only: reset module state between cases. */
export function resetForTest() {
  stopPolling();
  Object.assign(store, {
    screen: "lobby", code: null, joined: false, deck: null, status: "lobby",
    seq: -1, currentSeat: null, players: [], scores: {}, cells: {}, you: null,
    selected: "top", error: null, polling: false,
  });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `yarn test islands/tacta/lib/state.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add islands/tacta/lib/state.svelte.js islands/tacta/lib/state.test.js
git commit -m "feat(tacta): client state store with polling

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: UI components + styles

**Files:**
- Create: `islands/tacta/App.svelte`, `islands/tacta/components/{Lobby,Game,Board,Card,GameOver}.svelte`, `islands/tacta/styles/global.css`

No new failing test here (components are covered by the Task 5 mount smoke test, matching how
`islands/jobtracker` is tested). Create each file with the code below.

- [ ] **Step 1: `Card.svelte`** — renders one card's color, edge shapes, and dots.

Create `islands/tacta/components/Card.svelte`:
```svelte
<script>
  import { edgeAt, parseCardId, SIDES } from "../lib/cards.js";
  // props: cardId, deck (layouts), rotation, mirror, size
  let { cardId, deck, rotation = 0, mirror = false, size = 64 } = $props();

  const SHAPE = { triangle: "▲", square: "■", rectangle: "▬" };
  const parsed = $derived(parseCardId(cardId));
  const layout = $derived(deck ? deck[parsed.index] : null);
</script>

<div class="tcard tcard-{parsed.color}" style="width:{size}px;height:{size}px">
  {#if layout}
    {#each SIDES as side}
      {@const e = edgeAt(layout, side, rotation, mirror)}
      <span class="edge edge-{side.toLowerCase()}" class:filled={e.dots > 0}>
        {SHAPE[e.shape]}{#if e.dots > 0}<sup>{e.dots}</sup>{/if}
      </span>
    {/each}
    <span class="suit">{layout.value}</span>
  {/if}
</div>
```

- [ ] **Step 2: `Board.svelte`** — places cards on a grid, highlights legal cells, click to play.

Create `islands/tacta/components/Board.svelte`:
```svelte
<script>
  import { store, submitMove } from "../lib/state.svelte.js";
  import Card from "./Card.svelte";

  const CELL = 66;

  // Bounds across placed cards + legal targets, so the board auto-centers.
  const placed = $derived(Object.values(store.cells));
  const legal = $derived(
    store.you?.your_turn
      ? (store.you.legal ?? []).filter((m) =>
          store.selected === "top" ? m.draw_end === "top" : m.draw_end === "bottom",
        )
      : [],
  );
  // Deduplicate legal cells (first orientation wins for a given cell).
  const legalCells = $derived(
    Object.values(
      Object.fromEntries(legal.map((m) => [`${m.x},${m.y}`, m])),
    ),
  );

  const xs = $derived([...placed, ...legalCells, { x: 0, y: 0 }].map((c) => c.x));
  const ys = $derived([...placed, ...legalCells, { x: 0, y: 0 }].map((c) => c.y));
  const minX = $derived(Math.min(...xs));
  const minY = $derived(Math.min(...ys));
  const width = $derived((Math.max(...xs) - minX + 1) * CELL);
  const height = $derived((Math.max(...ys) - minY + 1) * CELL);

  function px(x) { return (x - minX) * CELL; }
  function py(y) { return (y - minY) * CELL; }
</script>

<div class="board-scroll">
  <div class="board" style="width:{width}px;height:{height}px">
    <!-- starting card -->
    <div class="cell" style="left:{px(0)}px;top:{py(0)}px">
      <div class="tcard tcard-start" style="width:{CELL - 2}px;height:{CELL - 2}px">★</div>
    </div>

    {#each placed as m (m.z)}
      <div class="cell" style="left:{px(m.x)}px;top:{py(m.y)}px;z-index:{m.z}">
        <Card cardId={m.card_id} deck={store.deck} rotation={m.rotation} mirror={m.mirror} size={CELL - 2} />
      </div>
    {/each}

    {#each legalCells as m (`${m.x},${m.y}`)}
      <button
        class="cell legal"
        style="left:{px(m.x)}px;top:{py(m.y)}px;width:{CELL - 2}px;height:{CELL - 2}px"
        onclick={() => submitMove(m)}
        aria-label="Place here"
      ></button>
    {/each}
  </div>
</div>
```

- [ ] **Step 3: `GameOver.svelte`**

Create `islands/tacta/components/GameOver.svelte`:
```svelte
<script>
  import { store } from "../lib/state.svelte.js";
  const ranked = $derived(
    [...store.players]
      .map((p) => ({ ...p, score: store.scores[p.color] ?? 0 }))
      .sort((a, b) => b.score - a.score),
  );
  const top = $derived(ranked.length ? ranked[0].score : 0);
</script>

<div class="over">
  <h1>Game over</h1>
  <ol class="ranking">
    {#each ranked as p}
      <li class:winner={p.score === top}>
        <span class="dot tcard-{p.color}"></span>{p.name} — <b>{p.score}</b>
      </li>
    {/each}
  </ol>
  <a class="btn" href="/tacta">New game</a>
</div>
```

- [ ] **Step 4: `Lobby.svelte`**

Create `islands/tacta/components/Lobby.svelte`:
```svelte
<script>
  import { store, createGame, joinGame, startGame } from "../lib/state.svelte.js";
  import { COLORS } from "../lib/cards.js";

  let name = $state("");
  let color = $state("");
  let codeInput = $state(store.code ?? "");

  const taken = $derived(new Set(store.players.map((p) => p.color)));
  const isHost = $derived(store.you?.seat === 0);
  const canStart = $derived(isHost && store.players.length >= 2);

  async function create() {
    const code = await createGame();
    codeInput = code;
  }
  function doJoin() {
    if (name.trim() && color) joinGame(codeInput.trim().toUpperCase(), name.trim(), color);
  }
</script>

<div class="lobby">
  <h1>Tacta</h1>
  {#if store.error}<p class="err">{store.error}</p>{/if}

  {#if !store.joined}
    <div class="panel">
      <button class="btn" onclick={create}>Create a game</button>
      <p class="hint">…or enter a code to join:</p>
      <input class="in" placeholder="ROOM CODE" bind:value={codeInput} maxlength="6" />
      <input class="in" placeholder="Your name" bind:value={name} maxlength="64" />
      <div class="colors">
        {#each COLORS as c}
          <button
            class="swatch tcard-{c}"
            class:sel={color === c}
            disabled={taken.has(c)}
            onclick={() => (color = c)}
            aria-label={c}
          ></button>
        {/each}
      </div>
      <button class="btn" onclick={doJoin} disabled={!name.trim() || !color}>Join</button>
    </div>
  {:else}
    <div class="panel">
      <p>Room code: <b class="code">{store.code}</b> — share it to invite players.</p>
      <ul class="seats">
        {#each store.players as p}
          <li><span class="dot tcard-{p.color}"></span>{p.name}{#if p.is_host} (host){/if}</li>
        {/each}
      </ul>
      {#if isHost}
        <button class="btn" onclick={startGame} disabled={!canStart}>
          {canStart ? "Start game" : "Need 2+ players"}
        </button>
      {:else}
        <p class="hint">Waiting for the host to start…</p>
      {/if}
    </div>
  {/if}
</div>
```

- [ ] **Step 5: `Game.svelte`**

Create `islands/tacta/components/Game.svelte`:
```svelte
<script>
  import { store, selectCard } from "../lib/state.svelte.js";
  import Board from "./Board.svelte";
  import Card from "./Card.svelte";

  const me = $derived(store.you);
  const myTurn = $derived(me?.your_turn ?? false);
  const currentName = $derived(
    store.players.find((p) => p.seat === store.currentSeat)?.name ?? "—",
  );
</script>

<div class="game">
  <header class="bar">
    <span class="turn" class:me={myTurn}>{myTurn ? "Your turn" : `${currentName}'s turn`}</span>
    <span class="scores">
      {#each store.players as p}
        <span class="score tcard-{p.color}">{store.scores[p.color] ?? 0}</span>
      {/each}
    </span>
  </header>

  {#if store.error}<p class="err">{store.error}</p>{/if}

  <Board />

  {#if myTurn && me?.hand}
    <footer class="hand">
      <span class="hint">Pick a card, then click a highlighted cell:</span>
      {#each [["top", me.hand.top], ["bottom", me.hand.bottom]] as [which, id]}
        {#if id}
          <button class="handcard" class:sel={store.selected === which} onclick={() => selectCard(which)}>
            <Card cardId={id} deck={store.deck} size={58} />
            <small>{which}</small>
          </button>
        {/if}
      {/each}
    </footer>
  {/if}
</div>
```

- [ ] **Step 6: `App.svelte`** — screen router + lifecycle.

Create `islands/tacta/App.svelte`:
```svelte
<script>
  import { onMount } from "svelte";
  import { store, joinGame, pollOnce, startPolling, stopPolling, loadDeck } from "./lib/state.svelte.js";
  import Lobby from "./components/Lobby.svelte";
  import Game from "./components/Game.svelte";
  import GameOver from "./components/GameOver.svelte";

  onMount(() => {
    // /tacta/{CODE} deep link: pre-fill the code so the lobby can join.
    const m = window.location.pathname.match(/^\/tacta\/([A-Z0-9]{6})$/i);
    if (m) store.code = m[1].toUpperCase();
    loadDeck();
    return () => stopPolling();
  });
</script>

{#if store.screen === "lobby"}
  <Lobby />
{:else if store.screen === "game"}
  <Game />
{:else}
  <GameOver />
{/if}
```

- [ ] **Step 7: `styles/global.css`** — reskin, aliasing the site theme tokens.

Create `islands/tacta/styles/global.css`:
```css
:root {
  --bg: var(--color-bg-subtle);
  --surface: var(--color-bg);
  --ink: var(--color-text);
  --muted: var(--color-muted);
  --line: var(--color-border);
  --accent: var(--color-accent);
  --mono: var(--font-mono);
  /* player tints */
  --c-blue: #3c7ebe; --c-green: #249057; --c-orange: #c48225;
  --c-pink: #d6559b; --c-purple: #7262b7; --c-red: #ce514d;
}
* { box-sizing: border-box; }
html, body { margin: 0; background: var(--bg); color: var(--ink); font-family: var(--mono); }

.lobby, .over { max-width: 560px; margin: 8vh auto; padding: 0 20px; text-align: center; }
h1 { font-family: var(--font-heading, var(--mono)); letter-spacing: 1px; }
.panel { border: var(--border-width, 3px) solid var(--line); background: var(--surface);
  box-shadow: var(--shadow, 4px 4px 0 var(--line)); padding: 20px; display: grid; gap: 12px; }
.btn { border: 3px solid var(--line); background: var(--accent); color: #111;
  padding: 10px 16px; font-weight: 700; box-shadow: 3px 3px 0 var(--line); }
.btn:disabled { opacity: .5; box-shadow: none; cursor: not-allowed; }
.in { border: 2px solid var(--line); padding: 9px; background: var(--surface); color: var(--ink); }
.hint { color: var(--muted); font-size: 13px; margin: 2px 0; }
.err { color: var(--c-red); font-weight: 700; }
.code { letter-spacing: 3px; }
.colors, .seats, .ranking { list-style: none; padding: 0; }
.colors { display: flex; gap: 8px; justify-content: center; }
.swatch { width: 34px; height: 34px; border: 3px solid var(--line); }
.swatch.sel { outline: 3px solid var(--ink); outline-offset: 2px; }
.swatch:disabled { opacity: .35; }
.seats li, .ranking li { display: flex; align-items: center; gap: 8px; justify-content: center; padding: 4px; }
.ranking li.winner { font-weight: 800; }
.dot { width: 14px; height: 14px; border: 2px solid var(--line); display: inline-block; }

/* color tints reused for swatches, dots, cards, scores */
.tcard-blue { background: var(--c-blue); } .tcard-green { background: var(--c-green); }
.tcard-orange { background: var(--c-orange); } .tcard-pink { background: var(--c-pink); }
.tcard-purple { background: var(--c-purple); } .tcard-red { background: var(--c-red); }
.tcard-start { background: repeating-linear-gradient(45deg,#000,#000 6px,#fff 6px,#fff 12px); color:#fff; }

/* game */
.game { display: flex; flex-direction: column; height: 100vh; }
.bar { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px;
  border-bottom: 3px solid var(--line); background: var(--surface); }
.turn { font-weight: 700; } .turn.me { background: var(--accent); padding: 4px 10px; border: 2px solid var(--line); }
.scores { display: flex; gap: 6px; }
.score { color: #fff; padding: 2px 8px; border: 2px solid var(--line); font-weight: 700; }
.board-scroll { flex: 1; overflow: auto; padding: 24px; }
.board { position: relative; margin: 0 auto; }
.cell { position: absolute; }
.tcard { position: relative; border: 2px solid #111; color: #fff; display: flex;
  align-items: center; justify-content: center; font-weight: 700; }
.tcard .suit { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; }
.tcard .edge { position: absolute; font-size: 11px; color: rgba(255,255,255,.85); line-height: 1; }
.tcard .edge sup { font-size: 8px; }
.tcard .edge.filled { color: #fff; }
.edge-n { top: 1px; left: 50%; transform: translateX(-50%); }
.edge-s { bottom: 1px; left: 50%; transform: translateX(-50%); }
.edge-e { right: 2px; top: 50%; transform: translateY(-50%); }
.edge-w { left: 2px; top: 50%; transform: translateY(-50%); }
.legal { background: rgba(250,204,21,.4); border: 2px dashed var(--ink); cursor: pointer; }
.legal:hover { background: rgba(250,204,21,.7); }
.hand { display: flex; gap: 12px; align-items: center; padding: 12px 16px;
  border-top: 3px solid var(--line); background: var(--surface); }
.handcard { border: 3px solid var(--line); background: var(--surface); padding: 6px; display: grid; justify-items: center; }
.handcard.sel { outline: 3px solid var(--accent); outline-offset: 2px; }
```

- [ ] **Step 8: Commit**

```bash
git add islands/tacta/App.svelte islands/tacta/components/ islands/tacta/styles/global.css
git commit -m "feat(tacta): lobby/game/board/card/game-over components + styles

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Mount smoke test + JS suite green

**Files:**
- Test: `islands/tacta.test.js`

- [ ] **Step 1: Write the test**

Create `islands/tacta.test.js`:
```js
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { mountIslands } from "./mount-islands.js";
import Tacta from "./tacta/App.svelte";

describe("tacta island", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = "";
    // App.onMount calls loadDeck() -> GET /tacta/api/deck.
    vi.stubGlobal("fetch", vi.fn(() =>
      Promise.resolve({ ok: true, text: () => Promise.resolve('{"layouts":[]}') }),
    ));
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("mounts the lobby and removes the SSR fallback", () => {
    document.body.innerHTML = '<div data-island="Tacta">loading…</div>';
    const host = document.querySelector("[data-island]");

    mountIslands({ Tacta }, document);

    expect(host.children.length).toBeGreaterThan(0);
    expect(host.textContent).toContain("Tacta"); // the lobby heading
    const bareText = [...host.childNodes].filter(
      (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== "",
    );
    expect(bareText).toEqual([]);
  });
});
```

- [ ] **Step 2: Run the full JS suite**

Run: `yarn test`
Expected: PASS — `cards.test.js`, `state.test.js`, `tacta.test.js`, and the pre-existing
`jobs.test.js` / `mount-islands.test.js`.

- [ ] **Step 3: Commit**

```bash
git add islands/tacta.test.js
git commit -m "test(tacta): island mount smoke test

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Build + manual end-to-end verification

- [ ] **Step 1: Build the bundles**

Run: `yarn build`
Expected: succeeds; `public/assets/.vite/manifest.json` now contains an `islands/tacta.js`
entry (so `tacta.twig`'s guarded include resolves).

- [ ] **Step 2: Run the dev server and play through a game**

Run (background): `php -S 127.0.0.1:8088 -t public public/router.php`
Then, with MySQL up and migrated, in a browser:
1. Open `http://127.0.0.1:8088/tacta` → click **Create a game**, pick a name + color, **Join**.
2. Open the room link in a second browser profile/incognito (so it gets its own cookie), join
   as a second color.
3. Back in the host window, **Start game**. Confirm the board shows the starting card and the
   current player sees their hand + highlighted legal cells.
4. Take turns placing cards (click a hand card, then a highlighted cell). Confirm moves appear
   in both windows within ~1.5s (polling), the turn banner and scores update, and covering an
   opponent's tab lowers their score.
5. Toggle the site theme (the cookie-based switch) and reload `/tacta` → confirm dark mode.

Record the result of each step. If any step fails, treat it as a bug to fix before completing.

- [ ] **Step 3: Final commit (only if step 1–2 required code changes)**

If manual verification surfaced fixes, commit them by name with a descriptive message. If no
changes were needed, there is nothing to commit here.

---

## Task 7: Full suites green

- [ ] **Step 1: JS + PHP suites**

Run:
```bash
yarn test
docker compose up -d && vendor/bin/phpunit
```
Expected: both green.

- [ ] **Step 2: Phase 4 complete**

---

## Self-review notes (already applied)

- **Spec coverage:** lobby (create/join/seats/start), game (board, hand, turn indicator, live
  scores, opponents' state via polling), game-over (final scores) — all present. Sync is the
  1.5s polling loop in `state.svelte.js`. The page shell from Phase 3 now resolves its bundle.
- **No engine on the client:** legality/scoring stay server-side; the client renders the
  server's `legal` list and the `deck` layouts. `edgeAt` is a 4-line render-only transform.
- **Deferred (acknowledged):** pan/zoom and animations (the board auto-sizes and scrolls);
  cycling multiple legal orientations at one cell (v1 uses the first per cell); reconnection
  polish; richer card art. These are nice-to-haves, not blockers for a playable game.
- **Type consistency:** card ids `{color}-{n}` (1-based) match the server; `cells` keyed by
  `"x,y"`; `draw_end`/rotation/mirror in submitted moves match the `move` endpoint contract;
  `you.legal` is filtered by the selected hand card's `draw_end`.
- **Manual verification is explicit** (Task 6) because a mount smoke test can't exercise the
  full multiplayer/polling/placement loop — the real proof is two browsers playing a game.
