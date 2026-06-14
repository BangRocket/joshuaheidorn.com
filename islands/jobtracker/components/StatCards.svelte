<script>
  import { store } from '../lib/state.svelte.js';
  import { ACTIVE } from '../lib/statuses.js';
  import { isStale } from '../lib/compute.js';

  const cards = $derived.by(() => {
    const jobs = store.jobs;
    const total = jobs.length;
    const appliedSet = jobs.filter((j) => j.status !== 'saved');
    const heard = jobs.filter((j) => ['interviewed', 'offer', 'rejected'].includes(j.status)).length;
    const respRate = appliedSet.length ? Math.round((heard / appliedSet.length) * 100) : 0;
    const active = jobs.filter((j) => ACTIVE.includes(j.status)).length;
    const stale = jobs.filter((j) => isStale(j, store.settings.stale_days)).length;
    const offers = jobs.filter((j) => j.status === 'offer').length;

    return [
      {
        lbl: 'Tracked',
        val: String(total),
        meta: offers ? `<b>${offers}</b> offer${offers > 1 ? 's' : ''} 🎉` : 'across all stages',
      },
      {
        lbl: 'Response rate',
        val: `${respRate}<small>%</small>`,
        meta: `${heard} of ${appliedSet.length} heard back`,
      },
      { lbl: 'Active', val: String(active), meta: 'awaiting a reply' },
      {
        lbl: 'Stale',
        val: String(stale),
        meta: `no update in ${store.settings.stale_days}+ days`,
        warnflag: stale > 0,
      },
    ];
  });
</script>

<div class="stats">
  {#each cards as c}
    <div class="card stat {c.warnflag ? 'warnflag' : ''}">
      <div class="lbl">{c.lbl}</div>
      <div class="val">{@html c.val}</div>
      <div class="meta">{@html c.meta}</div>
    </div>
  {/each}
</div>
