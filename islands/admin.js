import { mount } from "svelte";
import AdminLogin from "./AdminLogin.svelte";
import AdminUserButton from "./AdminUserButton.svelte";

const REGISTRY = { AdminLogin, AdminUserButton };

for (const el of document.querySelectorAll("[data-island]")) {
    const Component = REGISTRY[el.dataset.island];
    if (!Component) continue;
    const props = el.dataset.props ? JSON.parse(el.dataset.props) : {};
    mount(Component, { target: el, props });
}
