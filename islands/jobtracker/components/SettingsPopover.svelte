<script>
  import { store, patchSettings } from '../lib/state.svelte.js';

  let { open = false } = $props();

  function changeThreshold(e) {
    const v = Math.max(3, Math.min(90, parseInt(e.currentTarget.value) || 14));
    e.currentTarget.value = v;
    patchSettings({ stale_days: v });
  }
</script>

<div class="pop {open ? 'open' : ''}">
  <h4>Stale flag after</h4>
  <div class="thr">
    <input type="number" min="3" max="90" value={store.settings.stale_days} onchange={changeThreshold} />
    <span>days without an update</span>
  </div>

  <div class="toolnote">Data saves to the database automatically.</div>
</div>
