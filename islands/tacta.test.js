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
