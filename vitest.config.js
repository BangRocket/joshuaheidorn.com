import { defineConfig } from "vitest/config";
import { svelte } from "@sveltejs/vite-plugin-svelte";

// Separate from vite.config.js (the production island build) — this config only
// drives the Vitest + jsdom unit tests for the client islands.
export default defineConfig({
    plugins: [svelte({ emitCss: false })],
    test: {
        environment: "jsdom",
        include: ["islands/**/*.test.js"],
    },
    // Resolve Svelte's browser (client) build so mount() works under jsdom,
    // instead of the SSR build whose mount() throws. Per Svelte's Vitest docs.
    resolve: {
        conditions: ["browser"],
    },
});
