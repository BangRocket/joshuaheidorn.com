import { mount } from "svelte";
import Typewriter from "./Typewriter.svelte";
import Search from "./Search.svelte";

const REGISTRY = { Typewriter, Search };

for (const el of document.querySelectorAll("[data-island]")) {
    const Component = REGISTRY[el.dataset.island];
    if (!Component) continue;
    const props = el.dataset.props ? JSON.parse(el.dataset.props) : {};
    mount(Component, { target: el, props });
}
