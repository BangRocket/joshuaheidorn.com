import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { flushSync } from "svelte";
import { mountIslands } from "./mount-islands.js";
import Typewriter from "./Typewriter.svelte";

describe("mountIslands", () => {
    beforeEach(() => {
        // The Typewriter effect schedules timers; fake them so none leak.
        vi.useFakeTimers();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("removes the SSR fallback instead of leaving a stale duplicate", () => {
        // The span's text is the server-rendered fallback (e.g. headlines[0]).
        // Regression guard: Svelte 5 mount() appends, so without explicit
        // cleanup this fallback lingers forever next to the mounted component.
        document.body.innerHTML =
            '<span data-island="Typewriter" ' +
            "data-props='{\"phrases\":[\"First phrase\",\"Second phrase\"]}'>First phrase</span>";
        const host = document.querySelector("[data-island]");

        mountIslands({ Typewriter }, document);

        // The island actually mounted its UI...
        expect(host.querySelector(".typewriter")).not.toBeNull();

        // ...and the bare SSR fallback text node is gone (no duplicate prefix).
        const bareText = [...host.childNodes].filter(
            (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== "",
        );
        expect(bareText).toEqual([]);
        expect(host.textContent).not.toContain("First phraseFirst phrase");
    });

    it("leaves elements whose island is not registered untouched", () => {
        document.body.innerHTML = '<span data-island="Unknown">keep me</span>';
        const host = document.querySelector("[data-island]");

        mountIslands({ Typewriter }, document);

        expect(host.querySelector(".typewriter")).toBeNull();
        expect(host.textContent).toBe("keep me");
    });

    it("types past the first phrase over time (animation runs)", () => {
        document.body.innerHTML =
            '<span data-island="Typewriter" ' +
            "data-props='{\"phrases\":[\"AB\",\"CD\"],\"holdMs\":1800,\"deleteMs\":35}'>AB</span>";
        const host = document.querySelector("[data-island]");

        mountIslands({ Typewriter }, document);
        const shown = () => host.querySelector(".typewriter")?.textContent ?? "";

        // flushSync() runs Svelte's pending effects synchronously between fake-
        // timer advances, so the animation cascade is deterministic.
        flushSync(); // initial effect: schedules the "hold" timer
        expect(shown()).toContain("AB");

        vi.advanceTimersByTime(1800); // hold elapses -> phase becomes "deleting"
        flushSync(); // effect reschedules: a delete tick
        vi.advanceTimersByTime(35); // delete tick fires -> one char removed
        flushSync(); // DOM reflects the shortened text

        // The text changed, proving the reactive effect drives the animation.
        expect(shown()).not.toContain("AB");
    });
});
