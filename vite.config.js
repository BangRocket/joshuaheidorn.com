import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
    plugins: [svelte({ emitCss: false })],
    base: "/assets/",
    build: {
        manifest: true,
        outDir: "public/assets",
        emptyOutDir: true,
        rollupOptions: {
            input: "islands/main.js",
        },
    },
});
