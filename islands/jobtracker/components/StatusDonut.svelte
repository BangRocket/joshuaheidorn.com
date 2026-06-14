<script>
  import { store, setFilter } from '../lib/state.svelte.js';
  import { STATUSES } from '../lib/statuses.js';

  const data = $derived.by(() => {
    const counts = {};
    STATUSES.forEach((s) => (counts[s.k] = 0));
    store.jobs.forEach((j) => counts[j.status]++);

    const total = store.jobs.length || 1;
    let acc = 0;
    const segs = [];
    STATUSES.forEach((s) => {
      if (!counts[s.k]) return;
      const start = (acc / total) * 100;
      acc += counts[s.k];
      const end = (acc / total) * 100;
      segs.push(`var(--s-${s.k}) ${start}% ${end}%`);
    });

    return {
      bg: segs.length ? `conic-gradient(${segs.join(',')})` : 'var(--line)',
      total: store.jobs.length,
      legend: STATUSES.filter((s) => counts[s.k]).map((s) => ({ ...s, count: counts[s.k] })),
    };
  });
</script>

<div class="card chart">
  <h3>Status breakdown</h3>
  <div class="donut-row">
    <div class="donut" style="background:{data.bg}">
      <div class="hole"><div><b>{data.total}</b><span>tracked</span></div></div>
    </div>
    <div class="legend">
      {#if data.legend.length}
        {#each data.legend as s}
          <div
            class="li"
            role="button"
            tabindex="0"
            onclick={() => setFilter('status:' + s.k)}
            onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && setFilter('status:' + s.k)}
          >
            <span class="dot" style="background:var(--s-{s.k})"></span>
            <span class="nm">{s.label}</span>
            <span class="ct">{s.count}</span>
          </div>
        {/each}
      {:else}
        <span style="color:var(--faint);font-size:13px">No jobs yet</span>
      {/if}
    </div>
  </div>
</div>
