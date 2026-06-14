<script>
  import { store } from '../lib/state.svelte.js';
  import { today } from '../lib/compute.js';

  const buckets = $derived.by(() => {
    const t = today();
    const arr = [];
    for (let i = 5; i >= 0; i--) {
      const d = new Date(t.getFullYear(), t.getMonth() - i, 1);
      arr.push({
        y: d.getFullYear(),
        m: d.getMonth(),
        label: d.toLocaleDateString('en-US', { month: 'short' }),
        n: 0,
      });
    }
    store.jobs.forEach((j) => {
      const d = new Date(j.added + 'T00:00:00');
      const b = arr.find((b) => b.y === d.getFullYear() && b.m === d.getMonth());
      if (b) b.n++;
    });
    const max = Math.max(1, ...arr.map((b) => b.n));
    return arr.map((b) => ({ ...b, h: (b.n / max) * 100 }));
  });
</script>

<div class="card chart">
  <h3>Applications over time</h3>
  <div class="bars">
    {#each buckets as b}
      <div class="bar-col">
        <div class="bar" style="height:{b.h}%">
          {#if b.n}<span class="n">{b.n}</span>{/if}
        </div>
        <div class="m">{b.label}</div>
      </div>
    {/each}
  </div>
</div>
