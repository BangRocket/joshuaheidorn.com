import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
    plugins: [svelte({ emitCss: false })],
    base: "/assets/",
    // PHP serves all static files; we don't use Vite's publicDir copy. Disabling
    // it avoids Vite copying public/* (e.g. uploads) into the nested outDir.
    publicDir: false,
    build: {
        manifest: true,
        outDir: "public/assets",
        assetsDir: "",
        emptyOutDir: true,
        rollupOptions: {
            input: {
                main: "islands/main.js",
                admin: "islands/admin.js",
            },
        },
    },
});
