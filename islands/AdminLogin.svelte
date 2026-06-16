<script>
    import { ClerkProvider, SignIn } from "svelte-clerk/client";

    // redirectUrl: where to land after sign-in (validated server-side). Defaults to
    // the admin dashboard; set to the originally-requested guarded route (e.g. /jobs).
    let { publishableKey, redirectUrl = "/admin" } = $props();

    // A missing key would render a blank, non-functional sign-in — fail loudly.
    if (!publishableKey) {
        console.error("[AdminLogin] missing publishableKey (CLERK_PUBLISHABLE_KEY)");
    }
</script>

<ClerkProvider {publishableKey}>
    <!-- hash routing keeps Clerk's multi-step flow inside this one page
         (no SvelteKit catch-all route exists to handle path routing). -->
    <SignIn routing="hash" forceRedirectUrl={redirectUrl} />
</ClerkProvider>
