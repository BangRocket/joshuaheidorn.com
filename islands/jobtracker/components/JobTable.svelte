<script>
  import { store, visibleJobs, toggleSort, setFilter } from '../lib/state.svelte.js';
  import { SMAP } from '../lib/statuses.js';
  import { daysSince, isStale, fmtDate } from '../lib/compute.js';

  let { onRowClick } = $props();

  const COLS = [
    { key: 'company', label: 'Company' },
    { key: 'status', label: 'Status' },
    { key: 'location', label: 'Location' },
    { key: 'salary', label: 'Salary' },
    { key: 'added', label: 'Added' },
    { key: 'days', label: 'Last update', num: true },
    { key: 'link', label: 'Link' },
  ];

  const FILTERS = [
    { k: 'all', label: 'All' },
    { k: 'active', label: 'Active' },
    { k: 'interviewing', label: 'Interviewing' },
    { k: 'offers', label: 'Offers' },
    { k: 'stale', label: 'Stale ⚑' },
    { k: 'closed', label: 'Closed' },
  ];

  const rows = $derived(visibleJobs());

  function arrow(key) {
    if (store.sort.key !== key) return '↕';
    return store.sort.dir > 0 ? '▲' : '▼';
  }
</script>

<div class="card tablecard">
  <div class="tabletop">
    <h2>Applications <span class="cnt">· {rows.length}</span></h2>
    <div class="grow"></div>
    <div class="chips">
      {#each FILTERS as f}
        <button class="chip {store.filter === f.k ? 'on' : ''}" onclick={() => setFilter(f.k)}>
          {f.label}
        </button>
      {/each}
    </div>
  </div>

  {#if rows.length}
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            {#each COLS as c}
              <th
                class="{store.sort.key === c.key ? 'sorted' : ''} {c.num ? 'num' : ''}"
                onclick={() => toggleSort(c.key)}
              >
                {c.label}<span class="arr">{arrow(c.key)}</span>
              </th>
            {/each}
          </tr>
        </thead>
        <tbody>
          {#each rows as j (j.id)}
            {@const ds = daysSince(j.updated)}
            {@const stale = isStale(j, store.settings.stale_days)}
            <tr
              class={stale ? 'is-stale' : ''}
              role="button"
              tabindex="0"
              onclick={() => onRowClick(j.id)}
              onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && onRowClick(j.id)}
            >
              <td class="co">
                {j.company}
                {#if j.role}<div class="roleline">{j.role}</div>{/if}
              </td>
              <td>
                <span class="pill" style="background:var(--s-{j.status}-bg);color:var(--s-{j.status})">
                  <span class="pd" style="background:var(--s-{j.status})"></span>{SMAP[j.status].label}
                </span>
              </td>
              <td class="dim">{j.location || '—'}</td>
              <td class="mono">{j.salary || '—'}</td>
              <td class="dim">{fmtDate(j.added)}</td>
              <td class="num daycell">
                <span class="days">{ds}d</span>{#if stale}<span class="stale-tag">⚑ stale</span>{/if}
              </td>
              <td>
                {#if j.link}
                  <a
                    class="linkbtn"
                    href={j.link}
                    target="_blank"
                    rel="noopener"
                    onclick={(e) => e.stopPropagation()}>↗ open</a
                  >
                {:else}
                  <span class="nolink">—</span>
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {:else}
    <div class="empty">
      {#if store.jobs.length}
        <b>Nothing matches</b>Try a different filter or search.
      {:else}
        <b>No jobs yet</b>Click “Add job” to log your first application.
      {/if}
    </div>
  {/if}
</div>
