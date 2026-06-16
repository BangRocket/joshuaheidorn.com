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
  rotation: 0,          // chosen orientation of the selected card: 0..3 quarter-turns
  mirror: false,        // chosen face of the selected card
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

  // The game is final — stop the poll loop so a lingering game-over tab goes quiet.
  if (store.status === "done") stopPolling();

  // While it isn't our turn, keep the placement controls reset so each turn
  // starts from a clean orientation (top card, no rotation/flip).
  if (!store.you?.your_turn) {
    store.selected = "top";
    store.rotation = 0;
    store.mirror = false;
  }

  // If the selected hand card no longer exists (end of deck), fall back to the other,
  // so the board keeps showing legal targets instead of going blank.
  const hand = store.you?.hand;
  if (store.you?.your_turn && hand) {
    if (store.selected === "bottom" && !hand.bottom) store.selected = "top";
    else if (store.selected === "top" && !hand.top) store.selected = "bottom";
  }
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
  // Switching cards starts a fresh orientation choice.
  store.rotation = 0;
  store.mirror = false;
}

export function rotateCard() {
  store.rotation = (store.rotation + 1) % 4;
}

export function flipCard() {
  store.mirror = !store.mirror;
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
    selected: "top", rotation: 0, mirror: false, error: null, polling: false,
  });
}
