<script>
  import { store } from '../lib/state.svelte.js';

  const rows = $derived.by(() => {
    const jobs = store.jobs;
    const applied = jobs.filter((j) => j.status !== 'saved').length;
    const interviewed = jobs.filter((j) => ['interviewed', 'offer'].includes(j.status)).length;
    const offer = jobs.filter((j) => j.status === 'offer').length;
    const max = Math.max(1, applied);
    return [
      ['Applied', applied],
      ['Interviewed', interviewed],
      ['Offer', offer],
    ].map(([label, n]) => ({ label, n, w: Math.max(8, (n / max) * 100) }));
  });
</script>

<div class="card chart">
  <h3>Pipeline funnel</h3>
  <div class="funnel">
    {#each rows as r}
      <div class="fn">
        <span class="fl">{r.label}</span>
        <div class="ft" style="width:{r.w}%">{r.n}</div>
      </div>
    {/each}
  </div>
</div>
