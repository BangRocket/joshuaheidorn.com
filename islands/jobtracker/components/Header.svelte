<script>
  import { store } from '../lib/state.svelte.js';

  let { onAdd, onToggleSettings } = $props();

  // Dark mode rides the site's cookie + `.dark`/`.light` class switcher (no
  // per-app theme storage). Track it in $state so the toggle icon stays reactive.
  let dark = $state(
    typeof document !== 'undefined' && document.documentElement.classList.contains('dark'),
  );

  function toggleTheme() {
    dark = !dark;
    const root = document.documentElement;
    root.classList.remove('light', 'dark');
    root.classList.add(dark ? 'dark' : 'light');
    document.cookie = `theme=${dark ? 'dark' : 'light'};path=/;max-age=31536000;SameSite=Lax`;
  }
</script>

<header class="app">
  <div class="head-inner">
    <div class="logo">
      <div class="logo-mark"></div>
      <div>
        <h1>Job Tracker</h1>
        <div class="sub">your applications, one place</div>
      </div>
    </div>
    <div class="grow"></div>

    <div class="search-box">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
      </svg>
      <input
        type="text"
        placeholder="Search company or role…"
        value={store.query}
        oninput={(e) => (store.query = e.currentTarget.value.trim())}
      />
    </div>

    <button class="icon-btn" title="Toggle dark mode" onclick={toggleTheme} aria-label="Toggle dark mode">
      {#if dark}
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="4" />
          <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
        </svg>
      {:else}
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
        </svg>
      {/if}
    </button>

    <button
      class="icon-btn"
      title="Settings"
      aria-label="Settings"
      data-settings-btn
      onclick={onToggleSettings}
    >
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
      </svg>
    </button>

    <button class="btn primary" onclick={onAdd}>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
        <path d="M12 5v14M5 12h14" />
      </svg>
      Add job
    </button>
  </div>
</header>
