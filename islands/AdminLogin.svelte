<script>
    import { ClerkProvider, SignIn } from "svelte-clerk/client";

    let { publishableKey } = $props();

    // A missing key would render a blank, non-functional sign-in — fail loudly.
    if (!publishableKey) {
        console.error("[AdminLogin] missing publishableKey (CLERK_PUBLISHABLE_KEY)");
    }
</script>

<ClerkProvider {publishableKey}>
    <!-- hash routing keeps Clerk's multi-step flow inside this one page
         (no SvelteKit catch-all route exists to handle path routing). -->
    <SignIn routing="hash" forceRedirectUrl="/admin" />
</ClerkProvider>
