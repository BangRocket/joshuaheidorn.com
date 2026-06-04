<script>
    let { placeholder = "Search...", collections = ["posts", "projects", "pages"] } = $props();

    let query = $state("");
    let results = $state([]);
    let open = $state(false);
    let loading = $state(false);
    let timer;

    function onInput() {
        clearTimeout(timer);
        const q = query.trim();
        if (q.length < 2) {
            results = [];
            open = false;
            return;
        }
        loading = true;
        open = true;
        timer = setTimeout(async () => {
            const params = new URLSearchParams({ q });
            collections.forEach((c) => params.append("in[]", c));
            const res = await fetch(`/api/search?${params}`);
            results = res.ok ? await res.json() : [];
            loading = false;
        }, 180);
    }
</script>

<div class="site-search">
    <input
        class="site-search-input"
        type="search"
        {placeholder}
        bind:value={query}
        oninput={onInput}
        onfocus={() => { if (results.length) open = true; }}
    />
    {#if open}
        <div class="site-search-results">
            {#if loading}
                <div class="emdash-live-search-loading">Searching…</div>
            {:else if results.length === 0}
                <div class="emdash-live-search-no-results">No results</div>
            {:else}
                {#each results as r}
                    <a class="site-search-result" href={r.url}>
                        <span class="emdash-live-search-result-title">{r.title}</span>
                        <span class="emdash-live-search-result-collection">{r.collection}</span>
                        {#if r.snippet}
                            <span class="emdash-live-search-result-snippet">{r.snippet}</span>
                        {/if}
                    </a>
                {/each}
            {/if}
        </div>
    {/if}
</div>
