<script>
  import { onMount } from 'svelte';
  import { store, init } from './lib/state.svelte.js';
  import Header from './components/Header.svelte';
  import StatCards from './components/StatCards.svelte';
  import StatusDonut from './components/StatusDonut.svelte';
  import TimelineBars from './components/TimelineBars.svelte';
  import Funnel from './components/Funnel.svelte';
  import JobTable from './components/JobTable.svelte';
  import JobModal from './components/JobModal.svelte';
  import SettingsPopover from './components/SettingsPopover.svelte';

  let modalOpen = $state(false);
  let editingId = $state(null);
  let settingsOpen = $state(false);

  function openAdd() {
    editingId = null;
    modalOpen = true;
  }
  function openEdit(id) {
    editingId = id;
    modalOpen = true;
  }
  function closeModal() {
    modalOpen = false;
    editingId = null;
  }
  function toggleSettings() {
    settingsOpen = !settingsOpen;
  }

  onMount(() => {
    init();

    const onKey = (e) => {
      if (e.key === 'Escape') {
        if (modalOpen) closeModal();
        settingsOpen = false;
      }
    };
    // Close the settings popover when clicking outside it (but not on its trigger).
    const onClick = (e) => {
      if (!e.target.closest('.pop') && !e.target.closest('[data-settings-btn]')) {
        settingsOpen = false;
      }
    };
    window.addEventListener('keydown', onKey);
    window.addEventListener('click', onClick);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('click', onClick);
    };
  });
</script>

<Header onAdd={openAdd} onToggleSettings={toggleSettings} />
<SettingsPopover open={settingsOpen} />

<main class="wrap">
  {#if store.loading}
    <div class="empty"><b>Loading…</b>Fetching your applications.</div>
  {:else if store.error}
    <div class="empty"><b>Couldn’t load your data</b>{store.error}</div>
  {:else}
    <StatCards />

    <div class="charts">
      <StatusDonut />
      <TimelineBars />
      <Funnel />
    </div>

    <JobTable onRowClick={openEdit} />
  {/if}
</main>

<JobModal open={modalOpen} {editingId} onClose={closeModal} />
