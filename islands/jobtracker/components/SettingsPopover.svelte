<script>
  import { store, patchSettings } from '../lib/state.svelte.js';

  let { open = false } = $props();

  // Accent swatches as oklch "L C H" triples (matching the prototype).
  const SWATCHES = ['0.62 0.16 35', '0.58 0.12 250', '0.58 0.13 155', '0.55 0.13 290', '0.6 0.14 20'];

  function changeThreshold(e) {
    const v = Math.max(3, Math.min(90, parseInt(e.currentTarget.value) || 14));
    e.currentTarget.value = v;
    patchSettings({ stale_days: v });
  }
</script>

<div class="pop {open ? 'open' : ''}">
  <h4>Accent color</h4>
  <div class="swatches">
    {#each SWATCHES as acc}
      <div
        class="sw {store.settings.accent === acc ? 'sel' : ''}"
        style="background:oklch({acc})"
        role="button"
        tabindex="0"
        aria-label="Accent color"
        onclick={() => patchSettings({ accent: acc })}
        onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && patchSettings({ accent: acc })}
      ></div>
    {/each}
  </div>

  <h4>Stale flag after</h4>
  <div class="thr">
    <input type="number" min="3" max="90" value={store.settings.stale_days} onchange={changeThreshold} />
    <span>days without an update</span>
  </div>

  <div class="toolnote">Data saves to the database automatically.</div>
</div>
