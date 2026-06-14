<script>
    import { ClerkProvider, UserButton } from "svelte-clerk/client";

    let { publishableKey } = $props();

    // A missing key would render a blank, non-functional control — fail loudly.
    if (!publishableKey) {
        console.error("[AdminUserButton] missing publishableKey (CLERK_PUBLISHABLE_KEY)");
    }
</script>

<ClerkProvider {publishableKey}>
    <UserButton afterSignOutUrl="/admin/login" />
</ClerkProvider>
