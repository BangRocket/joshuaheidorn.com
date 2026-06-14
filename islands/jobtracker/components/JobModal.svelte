<script>
  import { untrack } from 'svelte';
  import { store, saveJob, removeJob } from '../lib/state.svelte.js';
  import { STATUSES } from '../lib/statuses.js';
  import { fmtDate, daysSince } from '../lib/compute.js';

  let { open = false, editingId = null, onClose } = $props();

  let company = $state('');
  let role = $state('');
  let link = $state('');
  let salary = $state('');
  let location = $state('');
  let description = $state('');
  let notes = $state('');
  let status = $state('applied');
  let companyError = $state(false);
  let saving = $state(false);
  let companyInput;

  const job = $derived(editingId ? store.jobs.find((j) => j.id === editingId) : null);

  // Populate the form only when the modal opens or its target changes — not when
  // the jobs list mutates underneath it.
  $effect(() => {
    open;
    editingId;
    untrack(() => {
      const j = editingId ? store.jobs.find((x) => x.id === editingId) : null;
      company = j?.company || '';
      role = j?.role || '';
      link = j?.link || '';
      salary = j?.salary || '';
      location = j?.location || '';
      description = j?.description || '';
      notes = j?.notes || '';
      status = j?.status || 'applied';
      companyError = false;
    });
    if (open) {
      setTimeout(() => companyInput?.focus(), 50);
    }
  });

  async function handleSave() {
    if (!company.trim()) {
      companyError = true;
      companyInput?.focus();
      return;
    }
    saving = true;
    try {
      await saveJob(editingId, { company, role, status, link, salary, location, description, notes });
      onClose();
    } catch (e) {
      store.error = e.message;
    } finally {
      saving = false;
    }
  }

  async function handleDelete() {
    if (!editingId) return;
    try {
      await removeJob(editingId);
      onClose();
    } catch (e) {
      store.error = e.message;
    }
  }

  function onOverlayClick(e) {
    if (e.target === e.currentTarget) onClose();
  }
</script>

<!-- Backdrop: click to dismiss; keyboard dismissal is handled by the global Escape handler. -->
<div class="overlay {open ? 'open' : ''}" role="presentation" onclick={onOverlayClick}>
  <div class="modal">
    <div class="modal-head">
      <h2>{editingId ? 'Edit job' : 'Add a job'}</h2>
      <button class="icon-btn" aria-label="Close" onclick={onClose}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path d="M18 6 6 18M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="modal-body">
      <div class="frow">
        <div class="field">
          <label for="f_co">Company <span class="req">*</span></label>
          <input
            id="f_co"
            type="text"
            placeholder="e.g. Northwind Labs"
            bind:this={companyInput}
            bind:value={company}
            oninput={() => (companyError = false)}
            style={companyError ? 'border-color:var(--s-rejected)' : ''}
          />
        </div>
        <div class="field">
          <label for="f_role">Role / title</label>
          <input id="f_role" type="text" placeholder="e.g. Frontend Engineer" bind:value={role} />
        </div>
      </div>

      <div class="field full">
        <!-- svelte-ignore a11y_label_has_associated_control -->
        <label>Status <span class="req">*</span></label>
        <div class="seg">
          {#each STATUSES as s}
            <button class={s.k === status ? 'sel' : ''} onclick={() => (status = s.k)}>
              <span class="pd" style="background:var(--s-{s.k})"></span>{s.label}
            </button>
          {/each}
        </div>
      </div>

      <div class="field full">
        <label for="f_link">Link <span class="auto">— job posting URL</span></label>
        <input id="f_link" type="url" placeholder="https://…" bind:value={link} />
      </div>

      <div class="frow">
        <div class="field">
          <label for="f_sal">Salary range <span class="auto">— optional</span></label>
          <input id="f_sal" type="text" placeholder="e.g. $140–160k" bind:value={salary} />
        </div>
        <div class="field">
          <label for="f_loc">Location <span class="auto">— optional</span></label>
          <input id="f_loc" type="text" placeholder="e.g. Remote · US" bind:value={location} />
        </div>
      </div>

      <div class="field full">
        <label for="f_desc">Description <span class="auto">— optional</span></label>
        <textarea id="f_desc" placeholder="What the role involves…" bind:value={description}></textarea>
      </div>

      <div class="field full">
        <label for="f_notes">Notes <span class="auto">— optional, just for you</span></label>
        <textarea id="f_notes" placeholder="Recruiter name, next steps, vibes…" bind:value={notes}></textarea>
      </div>

      <div class="field full" style="font-size:12.5px;color:var(--faint)">
        {#if job}
          Added {fmtDate(job.added)} · last update {daysSince(job.updated)} day{daysSince(job.updated) === 1
            ? ''
            : 's'} ago. Changing the status refreshes the “last update” date.
        {:else}
          Date added and the “last update” clock are filled in automatically when you save.
        {/if}
      </div>
    </div>

    <div class="modal-foot">
      {#if editingId}
        <button class="btn danger" onclick={handleDelete}>Delete</button>
      {/if}
      <div class="grow"></div>
      <button class="btn" onclick={onClose}>Cancel</button>
      <button class="btn primary" onclick={handleSave} disabled={saving}>Save job</button>
    </div>
  </div>
</div>
