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
