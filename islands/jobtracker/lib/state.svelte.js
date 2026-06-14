// Shared app state (Svelte 5 runes). `store` is a deeply-reactive object; the
// exported functions are the only things that mutate it. Components read from it
// and derive their views.
import { api } from './api.js';
import { SMAP, ACTIVE, CLOSED } from './statuses.js';
import { daysSince, isStale } from './compute.js';

const DEFAULT_SETTINGS = { accent: '0.62 0.16 35', stale_days: 14, theme: 'light' };

export const store = $state({
  jobs: [],
  settings: { ...DEFAULT_SETTINGS },
  filter: 'all',
  sort: { key: 'updated', dir: -1 },
  query: '',
  loading: true,
  error: null,
});

/* ---------------- theme / accent ---------------- */
export function applyAccent() {
  const [l, c, h] = store.settings.accent.split(' ');
  const r = document.documentElement.style;
  r.setProperty('--accent-l', l);
  r.setProperty('--accent-c', c);
  r.setProperty('--accent-h', h);
  const dark = store.settings.theme === 'dark';
  r.setProperty('--accent-soft', dark ? `oklch(0.33 0.06 ${h})` : `oklch(0.95 0.045 ${h})`);
  r.setProperty('--accent-ink', dark ? `oklch(0.8 0.11 ${h})` : `oklch(0.42 0.13 ${h})`);
}

export function applyTheme() {
  const dark = store.settings.theme === 'dark';
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  try {
    localStorage.setItem('jobtracker.theme', store.settings.theme);
  } catch (e) {
    /* private mode — ignore */
  }
  applyAccent();
}

/* ---------------- data loading ---------------- */
export async function init() {
  try {
    const [jobs, settings] = await Promise.all([api.listJobs(), api.getSettings()]);
    store.jobs = jobs;
    store.settings = settings;
  } catch (e) {
    store.error = e.message;
  } finally {
    store.loading = false;
    applyTheme();
  }
}

/* ---------------- job mutations ---------------- */
export async function saveJob(id, data) {
  if (id) {
    const updated = await api.updateJob(id, data);
    store.jobs = store.jobs.map((j) => (j.id === id ? updated : j));
  } else {
    const created = await api.createJob(data);
    store.jobs = [created, ...store.jobs];
  }
}

export async function removeJob(id) {
  await api.deleteJob(id);
  store.jobs = store.jobs.filter((j) => j.id !== id);
}

/* ---------------- settings mutations ---------------- */
export async function patchSettings(patch) {
  // Apply locally first for an instant response, then persist authoritatively.
  Object.assign(store.settings, patch);
  applyTheme();
  try {
    store.settings = await api.updateSettings(patch);
  } catch (e) {
    store.error = e.message;
  }
  applyTheme();
}

/* ---------------- filters ---------------- */
export function setFilter(filter) {
  store.filter = filter;
}

/* ---------------- derived: visible (filtered + sorted) jobs ---------------- */
export function visibleJobs() {
  const { filter, query, sort, settings } = store;
  let list = store.jobs.slice();

  if (filter === 'active') list = list.filter((j) => ACTIVE.includes(j.status));
  else if (filter === 'interviewing') list = list.filter((j) => j.status === 'interviewed');
  else if (filter === 'offers') list = list.filter((j) => j.status === 'offer');
  else if (filter === 'stale') list = list.filter((j) => isStale(j, settings.stale_days));
  else if (filter === 'closed') list = list.filter((j) => CLOSED.includes(j.status));
  else if (filter.startsWith('status:')) list = list.filter((j) => j.status === filter.slice(7));

  if (query) {
    const q = query.toLowerCase();
    list = list.filter((j) =>
      `${j.company} ${j.role} ${j.location}`.toLowerCase().includes(q),
    );
  }

  list.sort((a, b) => {
    let av, bv;
    if (sort.key === 'status') {
      av = SMAP[a.status].i;
      bv = SMAP[b.status].i;
    } else if (sort.key === 'days') {
      av = daysSince(a.updated);
      bv = daysSince(b.updated);
    } else if (sort.key === 'added' || sort.key === 'updated') {
      av = a[sort.key];
      bv = b[sort.key];
    } else {
      av = (a[sort.key] || '').toString().toLowerCase();
      bv = (b[sort.key] || '').toString().toLowerCase();
    }
    if (av < bv) return -sort.dir;
    if (av > bv) return sort.dir;
    return 0;
  });

  return list;
}

export function toggleSort(key) {
  const ascByDefault = ['company', 'location', 'salary'];
  if (store.sort.key === key) {
    store.sort = { key, dir: store.sort.dir * -1 };
  } else {
    store.sort = { key, dir: ascByDefault.includes(key) ? 1 : -1 };
  }
}
