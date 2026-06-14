import { mount } from "svelte";

/**
 * Mount Svelte islands onto every `[data-island]` element under `root`.
 *
 * Svelte 5 `mount()` APPENDS to the target rather than replacing it, so the
 * server-rendered fallback (progressive enhancement) would otherwise linger as
 * a stale duplicate. We capture the existing child nodes and remove them once
 * the island has mounted — leaving them in place if `mount()` throws, so a
 * mount failure degrades to the fallback instead of a blank slot.
 *
 * @param {Record<string, import("svelte").Component>} registry
 * @param {ParentNode} [root]
 */
export function mountIslands(registry, root = document) {
    for (const el of root.querySelectorAll("[data-island]")) {
        const Component = registry[el.dataset.island];
        if (!Component) continue;
        const props = el.dataset.props ? JSON.parse(el.dataset.props) : {};
        const fallback = [...el.childNodes];
        mount(Component, { target: el, props });
        fallback.forEach((node) => node.remove());
    }
}
