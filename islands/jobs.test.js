import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { mountIslands } from "./mount-islands.js";
import JobTracker from "./jobtracker/App.svelte";

describe("jobs island", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = "";
        // App.svelte's init() fetches /jobs/api/jobs and /jobs/api/settings.
        vi.stubGlobal("fetch", vi.fn((url) =>
            Promise.resolve({
                ok: true,
                text: () =>
                    Promise.resolve(String(url).includes("settings") ? '{"stale_days":14}' : "[]"),
            }),
        ));
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it("mounts the tracker and removes the SSR fallback", () => {
        document.body.innerHTML = '<div data-island="JobTracker">loading…</div>';
        const host = document.querySelector("[data-island]");

        mountIslands({ JobTracker }, document);

        // The component mounted (its root markup is present)...
        expect(host.children.length).toBeGreaterThan(0);
        // ...and the bare SSR fallback text node is gone.
        const bareText = [...host.childNodes].filter(
            (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== "",
        );
        expect(bareText).toEqual([]);
    });
});
